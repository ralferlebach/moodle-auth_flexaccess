<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * FlexAccess account lifecycle service.
 *
 * Creates temporary-user metadata and converts a temporary user to an authenticated user on
 * the same Moodle user id. Conversion never changes course enrolment (its duration is owned by
 * enrol_flexaccess); it only updates the account metadata and confirms the Moodle user.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace auth_flexaccess\local;

/**
 * Account creation and conversion.
 *
 * @package    auth_flexaccess
 */
final class account_service {
    /**
     * Account table.
     */
    private const TABLE = 'auth_flexaccess_account';

    /**
     * Generate a numeric reference code guaranteed not to collide with an existing account.
     *
     * A 12-digit space (10^12) with a pre-insert existence check makes a collision astronomically
     * unlikely, closing the window where a duplicate reference would fail the account insert after
     * the Moodle user was already created.
     *
     * @param int $digits Number of digits (default 12).
     * @return string
     */
    public static function generate_unique_reference(int $digits = 12): string {
        global $DB;
        $max = (10 ** $digits) - 1;
        do {
            $reference = str_pad((string) random_int(0, $max), $digits, '0', STR_PAD_LEFT);
        } while ($DB->record_exists(self::TABLE, ['referencecode' => $reference]));
        return $reference;
    }

    /**
     * Mark a temporary account as provisional (persistence requested, awaiting verification).
     *
     * Only an ephemeral temporary account is promoted; already-active or converted accounts are left
     * untouched. This keeps dashboard statistics and the account state machine consistent with the
     * lifecycle (anonymous = ephemeral, persistence-requested = provisional, verified = active).
     *
     * @param int $userid Moodle user id.
     * @param int|null $now Current time.
     * @return void
     */
    public static function mark_provisional(int $userid, ?int $now = null): void {
        global $DB;
        $now = $now ?? time();
        $account = $DB->get_record(self::TABLE, ['userid' => $userid]);
        if (
            !$account
                || $account->accounttype !== account_type::TEMPORARY_USER
                || $account->accountstate !== account_state::EPHEMERAL
        ) {
            return;
        }
        $account->accountstate = account_state::PROVISIONAL;
        $account->timemodified = $now;
        $DB->update_record(self::TABLE, $account);
    }

    /**
     * Create temporary-user account metadata.
     *
     * @param int $userid Moodle user id.
     * @param string $referencecode Unique human-facing reference code.
     * @param int|null $timeexpires Account expiry time; null means no expiry.
     * @param int|null $sourcecourseid Course the access originated from.
     * @param int|null $sourcecmid Course module the access originated from.
     * @param int|null $now Current time.
     * @return int New account row id.
     */
    public static function create_temporary(
        int $userid,
        string $referencecode,
        ?int $timeexpires = null,
        ?int $sourcecourseid = null,
        ?int $sourcecmid = null,
        ?int $now = null
    ): int {
        global $DB;
        $now = $now ?? time();
        return self::insert_with_reference_retry((object) [
            'userid' => $userid,
            'accounttype' => account_type::TEMPORARY_USER,
            'accountstate' => account_state::EPHEMERAL,
            'referencecode' => $referencecode,
            'sourcecourseid' => $sourcecourseid,
            'sourcecmid' => $sourcecmid,
            'timecreated' => $now,
            'timeexpires' => $timeexpires,
            'timeactivated' => null,
            'timemodified' => $now,
        ]);
    }

    /**
     * Insert an account row, regenerating the reference code if the (extremely rare) unique-index
     * collision occurs between generation and insert. This compensates the generate/insert race.
     *
     * @param \stdClass $record Account row to insert (referencecode may be regenerated).
     * @return int New account row id.
     */
    private static function insert_with_reference_retry(\stdClass $record): int {
        global $DB;
        $attempts = 0;
        while (true) {
            try {
                return (int) $DB->insert_record(self::TABLE, $record);
            } catch (\dml_write_exception $e) {
                // Only a reference-code clash is retryable; regenerate and try again a few times.
                if (
                    empty($record->referencecode) || ++$attempts >= 5
                        || !$DB->record_exists(self::TABLE, ['referencecode' => $record->referencecode])
                ) {
                    throw $e;
                }
                $record->referencecode = self::generate_unique_reference(strlen((string) $record->referencecode));
            }
        }
    }

    /**
     * Whether the given user currently holds a temporary FlexAccess account.
     *
     * @param int $userid Moodle user id.
     * @return bool
     */
    public static function is_temporary(int $userid): bool {
        global $DB;
        return $DB->record_exists(self::TABLE, [
            'userid' => $userid,
            'accounttype' => account_type::TEMPORARY_USER,
        ]);
    }

    /**
     * Create an account record that is persistent and immediately usable (quick registration).
     *
     * Unlike a temporary account this is an authenticated, active account with no expiry, so the
     * user can log in again later.
     *
     * @param int $userid Moodle user id.
     * @param string|null $referencecode Optional reference code.
     * @param int|null $now Current time.
     * @return int New account record id.
     */
    public static function create_authenticated(int $userid, ?string $referencecode = null, ?int $now = null): int {
        global $DB;
        $now = $now ?? time();
        return self::insert_with_reference_retry((object) [
            'userid' => $userid,
            'accounttype' => account_type::AUTHENTICATED_USER,
            'accountstate' => account_state::ACTIVE,
            'referencecode' => $referencecode,
            'sourcecourseid' => null,
            'sourcecmid' => null,
            'timecreated' => $now,
            'timeexpires' => null,
            'timeactivated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Convert a temporary account to a persistent, authenticated account.
     *
     * @param int $userid Moodle user id.
     * @param int|null $now Current time.
     * @return bool
     */
    public static function convert_to_authenticated(int $userid, ?int $now = null): bool {
        global $DB;
        $now = $now ?? time();
        $account = $DB->get_record(self::TABLE, ['userid' => $userid]);
        if (!$account || $account->accounttype !== account_type::TEMPORARY_USER) {
            return false;
        }
        $account->accounttype = account_type::AUTHENTICATED_USER;
        $account->accountstate = account_state::ACTIVE;
        $account->timeactivated = $now;
        $account->timeexpires = null;
        $account->timemodified = $now;
        $DB->update_record(self::TABLE, $account);

        // Confirm the Moodle user; enrolment and its duration are intentionally left untouched.
        $DB->set_field('user', 'confirmed', 1, ['id' => $userid]);
        // The account is now a full identity, so lift the anonymous-visitor site restrictions.
        // enrol_flexaccess owns that role; guard the call so auth does not hard-depend on enrol
        // (enrol depends on auth, not the reverse) and unit tests can run auth in isolation.
        if (class_exists('\enrol_flexaccess\local\participant_role')) {
            \enrol_flexaccess\local\participant_role::unrestrict($userid);
        }
        return true;
    }

    /**
     * Whether an account may currently undergo an identity conversion.
     *
     * Central guard for every conversion path (self-activation, admin conversion, persistence
     * confirmation): the account must still be a live temporary account — the right type, not
     * expired by state, and not past its expiry time. This prevents reviving an account that has
     * already lapsed or been suspended.
     *
     * @param int $userid User id.
     * @param int|null $now Current time.
     * @return bool
     */
    public static function is_convertible(int $userid, ?int $now = null): bool {
        global $DB;
        $now = $now ?? time();
        $account = $DB->get_record(self::TABLE, ['userid' => $userid]);
        if (!$account || $account->accounttype !== account_type::TEMPORARY_USER) {
            return false;
        }
        if ($account->accountstate === account_state::EXPIRED || $account->accountstate === account_state::SUSPENDED) {
            return false;
        }
        if ($account->timeexpires !== null && (int) $account->timeexpires > 0 && (int) $account->timeexpires <= $now) {
            return false;
        }
        return true;
    }

    /**
     * Expire due temporary accounts and suspend their Moodle users.
     *
     * Idempotent and batched: only temporary accounts with a passed expiry that are not already
     * expired are processed. Enrolment is not touched here (owned by enrol_flexaccess).
     *
     * @param int|null $now Current time.
     * @param int $limit Maximum number of accounts to process in one run.
     * @return int Number of accounts expired.
     */
    public static function expire_due(?int $now = null, int $limit = 500): int {
        global $DB;
        $now = $now ?? time();
        $select = "accounttype = :type AND timeexpires > 0 AND timeexpires <= :now AND accountstate <> :expired";
        $params = [
            'type' => account_type::TEMPORARY_USER,
            'now' => $now,
            'expired' => account_state::EXPIRED,
        ];
        $records = $DB->get_records_select(self::TABLE, $select, $params, 'timeexpires ASC', '*', 0, max(0, $limit));
        $count = 0;
        foreach ($records as $account) {
            $account->accountstate = account_state::EXPIRED;
            $account->timemodified = $now;
            $DB->update_record(self::TABLE, $account);
            $DB->set_field('user', 'suspended', 1, ['id' => $account->userid]);
            $count++;
        }
        return $count;
    }

    /**
     * Permanently delete temporary accounts that expired longer ago than the retention period.
     *
     * Enforces a real deletion lifecycle: once the retention window has passed, the temporary Moodle
     * user is deleted and all FlexAccess artefacts (account row, tokens, queued mail) are removed, so
     * abandoned anonymous accounts do not linger indefinitely. A retention of zero disables purging.
     *
     * @param int|null $now Current time.
     * @param int $retention Seconds to keep an expired account before deletion.
     * @param int $limit Maximum accounts to process in one run.
     * @return int Number of accounts purged.
     */
    public static function purge_expired(?int $now = null, int $retention = 0, int $limit = 200): int {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/user/lib.php');
        $now = $now ?? time();
        if ($retention <= 0) {
            return 0;
        }
        $cutoff = $now - $retention;
        $select = 'accounttype = :type AND accountstate = :state AND timeexpires > 0 AND timeexpires <= :cutoff';
        $params = [
            'type' => account_type::TEMPORARY_USER,
            'state' => account_state::EXPIRED,
            'cutoff' => $cutoff,
        ];
        $records = $DB->get_records_select(self::TABLE, $select, $params, 'timeexpires ASC', '*', 0, max(0, $limit));
        $count = 0;
        foreach ($records as $account) {
            self::delete_account((int) $account->userid);
            $count++;
        }
        return $count;
    }

    /**
     * Delete a FlexAccess account and its Moodle user, Moodle-user first.
     *
     * Ordering matters for reliability (§ purge): the Moodle user (and thereby its enrolments) is
     * removed first; the FlexAccess metadata is only cleared once that has succeeded, so a failing
     * core delete can never leave a Moodle user stripped of its FlexAccess record.
     *
     * @param int $userid Moodle user id.
     * @return void
     */
    public static function delete_account(int $userid): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/user/lib.php');
        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0]);
        if ($user) {
            delete_user($user);
        }
        $transaction = $DB->start_delegated_transaction();
        $DB->delete_records('auth_flexaccess_token', ['userid' => $userid]);
        $DB->delete_records('auth_flexaccess_mailqueue', ['userid' => $userid]);
        $DB->delete_records(self::TABLE, ['userid' => $userid]);
        $transaction->allow_commit();
    }
}
