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
use auth_flexaccess\local\account_state;
use auth_flexaccess\local\account_service;
use auth_flexaccess\local\followup_scheduler;
use auth_flexaccess\local\mail_kind;

/**
 * Cross-plugin facade for FlexAccess account classification and the follow-up funnel.
 */
final class api {
    /**
     * Mail-queue table.
     */
    private const QUEUE_TABLE = 'auth_flexaccess_mailqueue';
    /**
     * Account table.
     */
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
    public static function request_persistence_followup(
        int $userid,
        int $afterseconds,
        ?int $safetymargin = null
    ): bool {
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

    /**
     * Self-activate a logged-in temporary user, capturing e-mail and name.
     *
     * Immediate in-session activation: the temporary user is already authenticated in the
     * session, so this converts the same account to an authenticated user after capturing a
     * deliverable, non-duplicate e-mail. The e-mail-verified path is available separately via the
     * follow-up funnel. Enrolment and its duration are not changed.
     *
     * @param int $userid Logged-in user id.
     * @param string $email Captured e-mail address.
     * @param string $firstname Optional first name to set.
     * @param string $lastname Optional last name to set.
     * @return string One of: activated, notapplicable, invalidemail, emailtaken.
     */
    public static function self_activate(
        int $userid,
        string $email,
        string $firstname = '',
        string $lastname = ''
    ): string {
        global $DB;

        if (self::classify_user($userid) !== account_type::TEMPORARY_USER) {
            return 'notapplicable';
        }
        $email = \core_text::strtolower(trim($email));
        if ($email === '' || !validate_email($email)) {
            return 'invalidemail';
        }
        $taken = $DB->record_exists_select(
            'user',
            'LOWER(email) = :email AND id <> :id AND deleted = 0',
            ['email' => $email, 'id' => $userid]
        );
        if ($taken) {
            return 'emailtaken';
        }

        $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
        $user->email = $email;
        if (trim($firstname) !== '') {
            $user->firstname = clean_param($firstname, PARAM_NOTAGS);
        }
        if (trim($lastname) !== '') {
            $user->lastname = clean_param($lastname, PARAM_NOTAGS);
        }
        $DB->update_record('user', $user);

        account_service::convert_to_authenticated($userid);
        return 'activated';
    }

    /**
     * Create a temporary Moodle user with FlexAccess account metadata.
     *
     * The user is created with the FlexAccess auth method, a generated username, no local
     * password (set only on activation) and a non-deliverable placeholder e-mail with mail
     * disabled. The account lifetime (expiry) is supplied by the caller from the enrol policy.
     *
     * @param int|null $timeexpires Account expiry time; null means no expiry.
     * @param int|null $sourcecourseid Course the access originated from.
     * @param int|null $sourcecmid Course module the access originated from.
     * @param int|null $now Current time.
     * @return int The new user id.
     */
    public static function create_temporary_user(
        ?int $timeexpires = null,
        ?int $sourcecourseid = null,
        ?int $sourcecmid = null,
        ?int $now = null
    ): int {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/user/lib.php');
        $now = $now ?? time();

        $reference = \core_text::strtoupper(substr(sha1(uniqid('', true)), 0, 8));
        do {
            $username = \core_text::strtolower('flexaccess_' . $reference . random_string(4));
        } while ($DB->record_exists('user', ['username' => $username, 'mnethostid' => $CFG->mnet_localhost_id]));

        $user = new \stdClass();
        $user->auth = 'flexaccess';
        $user->confirmed = 1;
        $user->mnethostid = $CFG->mnet_localhost_id;
        $user->username = $username;
        $user->password = '';
        $user->firstname = get_string('temporaryfirstname', 'auth_flexaccess');
        $user->lastname = get_string('temporarylastname', 'auth_flexaccess');
        $user->email = $username . '@flexaccess.invalid';
        $user->emailstop = 1;
        $user->policyagreed = 1;

        $userid = (int) user_create_user($user, false, true);
        account_service::create_temporary($userid, $reference, $timeexpires, $sourcecourseid, $sourcecmid, $now);
        return $userid;
    }

    /**
     * Aggregate account counts for dashboards.
     *
     * @return array<string, int>
     */
    public static function account_stats(): array {
        global $DB;
        $t = self::ACCOUNT_TABLE;
        return [
            'total' => $DB->count_records($t),
            'temporary' => $DB->count_records($t, ['accounttype' => account_type::TEMPORARY_USER]),
            'authenticated' => $DB->count_records($t, ['accounttype' => account_type::AUTHENTICATED_USER]),
            'provisional' => $DB->count_records($t, ['accountstate' => account_state::PROVISIONAL]),
            'active' => $DB->count_records($t, ['accountstate' => account_state::ACTIVE]),
            'expired' => $DB->count_records($t, ['accountstate' => account_state::EXPIRED]),
        ];
    }

    /**
     * Summarise the mail queue for dashboards.
     *
     * @return array<string, int>
     */
    public static function mailqueue_summary(): array {
        global $DB;
        $nextdue = $DB->get_field_sql(
            "SELECT MIN(nextrun) FROM {" . self::QUEUE_TABLE . "} WHERE status = ?",
            ['queued']
        );
        return [
            'queued' => $DB->count_records(self::QUEUE_TABLE, ['status' => 'queued']),
            'sent' => $DB->count_records(self::QUEUE_TABLE, ['status' => 'sent']),
            'failed' => $DB->count_records(self::QUEUE_TABLE, ['status' => 'failed']),
            'nextdue' => $nextdue ? (int) $nextdue : 0,
        ];
    }

    /**
     * Count mail-queue rows, optionally filtered by status.
     *
     * @param string $status Optional status filter.
     * @return int
     */
    public static function count_mailqueue(string $status = ''): int {
        global $DB;
        $conditions = $status !== '' ? ['status' => $status] : [];
        return $DB->count_records(self::QUEUE_TABLE, $conditions);
    }

    /**
     * List mail-queue rows (no secrets; payload is excluded), newest first.
     *
     * @param string $status Optional status filter.
     * @param int $page Zero-based page index.
     * @param int $perpage Page size.
     * @return array<\stdClass>
     */
    public static function list_mailqueue(string $status = '', int $page = 0, int $perpage = 50): array {
        global $DB;
        $conditions = $status !== '' ? ['status' => $status] : [];
        $rows = $DB->get_records(
            self::QUEUE_TABLE,
            $conditions,
            'timecreated DESC',
            'id, recipient, mailtype, status, attempts, timecreated, nextrun, timesent',
            $page * $perpage,
            $perpage
        );
        return array_values($rows);
    }

    /**
     * Search FlexAccess accounts (read-only), joined with user identity.
     *
     * @param string $query Substring matched against e-mail and name.
     * @param string|null $type Optional account-type filter.
     * @param string|null $state Optional account-state filter.
     * @param int $page Zero-based page index.
     * @param int $perpage Page size.
     * @return array<\stdClass>
     */
    public static function search_accounts(
        string $query = '',
        ?string $type = null,
        ?string $state = null,
        int $page = 0,
        int $perpage = 50
    ): array {
        global $DB;
        [$where, $params] = self::build_account_filter($query, $type, $state);
        $sql = "SELECT a.id, a.userid, a.accounttype, a.accountstate, a.timecreated, a.timeexpires,
                       u.firstname, u.lastname, u.email
                  FROM {auth_flexaccess_account} a
                  JOIN {user} u ON u.id = a.userid
                 WHERE $where
              ORDER BY a.timecreated DESC";
        return array_values($DB->get_records_sql($sql, $params, $page * $perpage, $perpage));
    }

    /**
     * Count FlexAccess accounts matching a filter.
     *
     * @param string $query Substring matched against e-mail and name.
     * @param string|null $type Optional account-type filter.
     * @param string|null $state Optional account-state filter.
     * @return int
     */
    public static function count_accounts(string $query = '', ?string $type = null, ?string $state = null): int {
        global $DB;
        [$where, $params] = self::build_account_filter($query, $type, $state);
        $sql = "SELECT COUNT(a.id)
                  FROM {auth_flexaccess_account} a
                  JOIN {user} u ON u.id = a.userid
                 WHERE $where";
        return (int) $DB->count_records_sql($sql, $params);
    }

    /**
     * Convert an account administratively (capability checks belong to the caller).
     *
     * @param int $userid User id.
     * @return bool Whether a conversion happened.
     */
    public static function admin_convert(int $userid): bool {
        return account_service::convert_to_authenticated($userid);
    }

    /**
     * Build the WHERE clause and parameters for an account filter.
     *
     * @param string $query Substring matched against e-mail and name.
     * @param string|null $type Optional account-type filter.
     * @param string|null $state Optional account-state filter.
     * @return array{0: string, 1: array}
     */
    private static function build_account_filter(string $query, ?string $type, ?string $state): array {
        global $DB;
        $where = ['u.deleted = 0'];
        $params = [];
        if ($type !== null && in_array($type, account_type::values(), true)) {
            $where[] = 'a.accounttype = :type';
            $params['type'] = $type;
        }
        if ($state !== null && in_array($state, account_state::values(), true)) {
            $where[] = 'a.accountstate = :state';
            $params['state'] = $state;
        }
        $query = trim($query);
        if ($query !== '') {
            $like = '%' . $DB->sql_like_escape($query) . '%';
            $email = $DB->sql_like('u.email', ':qe', false);
            $first = $DB->sql_like('u.firstname', ':qf', false);
            $last = $DB->sql_like('u.lastname', ':ql', false);
            $where[] = "($email OR $first OR $last)";
            $params['qe'] = $like;
            $params['qf'] = $like;
            $params['ql'] = $like;
        }
        return [implode(' AND ', $where), $params];
    }
}
