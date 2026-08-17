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
 * Public facade for auth_flexaccess.
 *
 * The stable cross-plugin entry point consumed by enrol/tool/mod. It classifies users, reads
 * account metadata and enqueues persistence follow-up mails. Tokens and the actual sending are
 * handled by the mail worker; this facade never places a secret in the queue.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace auth_flexaccess;

use auth_flexaccess\local\account_type;
use auth_flexaccess\local\followup_scheduler;
use auth_flexaccess\local\mail_kind;

/** Cross-plugin facade for FlexAccess account classification and the follow-up funnel. */
final class api {
    /** Mail-queue table. */
    private const QUEUE_TABLE = 'auth_flexaccess_mailqueue';
    /** Account table. */
    private const ACCOUNT_TABLE = 'auth_flexaccess_account';

    /**
     * Classify a user as a FlexAccess account type.
     *
     * A user without a FlexAccess account record is treated as an authenticated user, so
     * callers can branch safely without a FlexAccess metadata row existing.
     *
     * @param int $userid User id.
     * @return string One of account_type::TEMPORARY_USER or account_type::AUTHENTICATED_USER.
     */
    public static function classify_user(int $userid): string {
        $account = self::get_account($userid);
        if ($account && $account->accounttype === account_type::TEMPORARY_USER) {
            return account_type::TEMPORARY_USER;
        }
        return account_type::AUTHENTICATED_USER;
    }

    /**
     * Load the FlexAccess account metadata for a user.
     *
     * @param int $userid User id.
     * @return \stdClass|null
     */
    public static function get_account(int $userid): ?\stdClass {
        global $DB;
        $record = $DB->get_record(self::ACCOUNT_TABLE, ['userid' => $userid]);
        return $record ?: null;
    }

    /**
     * Schedule a persistence follow-up mail for a temporary user, if appropriate.
     *
     * Idempotent: does nothing if a follow-up is already queued for the user. Returns false when
     * the account does not qualify, has no deliverable address, or cannot fit a reminder before
     * expiry.
     *
     * @param int $userid User id.
     * @param int $afterseconds Delay after account creation before sending.
     * @param int|null $safetymargin Seconds the follow-up must precede expiry by.
     * @return bool Whether a follow-up was scheduled.
     */
    public static function request_persistence_followup(int $userid, int $afterseconds,
            ?int $safetymargin = null): bool {
        global $DB;

        $account = self::get_account($userid);
        if (!$account) {
            return false;
        }
        if (!followup_scheduler::should_schedule($account->accounttype, $account->accountstate)) {
            return false;
        }

        $user = $DB->get_record('user', ['id' => $userid], 'id, email', IGNORE_MISSING);
        if (!$user || empty($user->email) || !validate_email($user->email)) {
            return false;
        }

        $margin = $safetymargin ?? followup_scheduler::DEFAULT_SAFETY_MARGIN;
        $expiry = $account->timeexpires !== null ? (int) $account->timeexpires : null;
        $due = followup_scheduler::due_time((int) $account->timecreated, $afterseconds, $expiry, $margin);
        if ($due === null) {
            return false;
        }

        // Idempotency: never queue a second pending follow-up for the same user.
        $pending = $DB->record_exists_select(
            self::QUEUE_TABLE,
            "userid = :userid AND mailtype = :kind AND status = :status",
            ['userid' => $userid, 'kind' => mail_kind::PERSISTENCE_FOLLOWUP, 'status' => 'queued']
        );
        if ($pending) {
            return false;
        }

        $now = time();
        $DB->insert_record(self::QUEUE_TABLE, (object) [
            'userid' => $userid,
            'recipient' => $user->email,
            'mailtype' => mail_kind::PERSISTENCE_FOLLOWUP,
            'payloadjson' => json_encode(['kind' => mail_kind::PERSISTENCE_FOLLOWUP]),
            'status' => 'queued',
            'attempts' => 0,
            'timecreated' => $now,
            'nextrun' => $due,
            'timesent' => null,
        ]);
        return true;
    }
}
