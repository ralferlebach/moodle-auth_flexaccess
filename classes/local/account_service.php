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
 */
final class account_service {
    /**
     * Account table.
     */
    private const TABLE = 'auth_flexaccess_account';

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
        return (int) $DB->insert_record(self::TABLE, (object) [
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
     * Convert a temporary user to an authenticated user on the same user id.
     *
     * Idempotent: returns false when there is no temporary account to convert. Does not touch
     * course enrolment.
     *
     * @param int $userid Moodle user id.
     * @param int|null $now Current time.
     * @return bool Whether a conversion happened.
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
}
