<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace auth_flexaccess\local;

/**
 * Read-only queries over FlexAccess accounts and the mail queue.
 *
 * Split out of the api facade so the reporting/listing paths are reviewable on their own, separate
 * from the identity transitions. The facade delegates to this service and keeps its signatures.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class account_query_service {
    /** Mail queue table. */
    private const QUEUE_TABLE = 'auth_flexaccess_mailqueue';

    /** FlexAccess account table. */
    private const ACCOUNT_TABLE = 'auth_flexaccess_account';

    /**
     * Aggregate account counts for dashboards.
     *
     * @return array<string, int>
     */
    public static function account_stats(): array {
        global $DB;
        $t = self::ACCOUNT_TABLE;
        // A single conditional-aggregate pass instead of six separate COUNT queries.
        $row = $DB->get_record_sql(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN accounttype = :tt THEN 1 ELSE 0 END) AS temporary,
                    SUM(CASE WHEN accounttype = :ta THEN 1 ELSE 0 END) AS authenticated,
                    SUM(CASE WHEN accountstate = :sp THEN 1 ELSE 0 END) AS provisional,
                    SUM(CASE WHEN accountstate = :sac THEN 1 ELSE 0 END) AS active,
                    SUM(CASE WHEN accountstate = :se THEN 1 ELSE 0 END) AS expired
               FROM {" . $t . "}",
            [
                'tt' => account_type::TEMPORARY_USER,
                'ta' => account_type::AUTHENTICATED_USER,
                'sp' => account_state::PROVISIONAL,
                'sac' => account_state::ACTIVE,
                'se' => account_state::EXPIRED,
            ]
        );
        return [
            'total' => (int) $row->total,
            'temporary' => (int) $row->temporary,
            'authenticated' => (int) $row->authenticated,
            'provisional' => (int) $row->provisional,
            'active' => (int) $row->active,
            'expired' => (int) $row->expired,
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
     * @param string|null $reference Optional exact reference-number match.
     * @param string|null $condition Optional recovery filter: usersuspended, enrolsuspended, verificationpending.
     * @return array<\stdClass>
     */
    public static function search_accounts(
        string $query = '',
        ?string $type = null,
        ?string $state = null,
        int $page = 0,
        int $perpage = 50,
        ?string $reference = null,
        ?string $condition = null
    ): array {
        global $DB;
        [$where, $params] = self::build_account_filter($query, $type, $state, $reference, $condition);
        $sql = "SELECT a.id, a.userid, a.accounttype, a.accountstate, a.timecreated, a.timeexpires,
                       a.referencecode, a.sourcecourseid, u.firstname, u.lastname, u.email, u.suspended
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
     * @param string|null $reference Optional exact reference-number match.
     * @param string|null $condition Optional recovery filter (see search_accounts()).
     * @return int
     */
    public static function count_accounts(
        string $query = '',
        ?string $type = null,
        ?string $state = null,
        ?string $reference = null,
        ?string $condition = null
    ): int {
        global $DB;
        [$where, $params] = self::build_account_filter($query, $type, $state, $reference, $condition);
        $sql = "SELECT COUNT(a.id)
                  FROM {auth_flexaccess_account} a
                  JOIN {user} u ON u.id = a.userid
                 WHERE $where";
        return (int) $DB->count_records_sql($sql, $params);
    }

    /**
     * Build the WHERE clause and parameters for an account filter.
     *
     * @param string $query Substring matched against e-mail and name.
     * @param string|null $type Optional account-type filter.
     * @param string|null $state Optional account-state filter.
     * @param string|null $reference Optional exact reference-number match.
     * @param string|null $condition Optional recovery filter (see search_accounts()).
     * @return array{0: string, 1: array}
     */
    private static function build_account_filter(
        string $query,
        ?string $type,
        ?string $state,
        ?string $reference = null,
        ?string $condition = null
    ): array {
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
        if ($condition === 'usersuspended') {
            $where[] = 'u.suspended = 1';
        } else if ($condition === 'enrolsuspended') {
            $where[] = "EXISTS (SELECT 1 FROM {user_enrolments} fue
                                  JOIN {enrol} fe ON fe.id = fue.enrolid AND fe.enrol = 'flexaccess'
                                 WHERE fue.userid = a.userid AND fue.status = :suspendedstatus)";
            $params['suspendedstatus'] = ENROL_USER_SUSPENDED;
        } else if ($condition === 'verificationpending') {
            $where[] = "a.accounttype = :vtype AND EXISTS (SELECT 1 FROM {user_preferences} fp
                                  WHERE fp.userid = a.userid AND fp.name = :vpref)";
            $params['vtype'] = account_type::TEMPORARY_USER;
            $params['vpref'] = 'auth_flexaccess_pendingemail';
        }
        $query = trim($query);
        $reference = $reference !== null ? trim($reference) : '';
        if ($query !== '' || $reference !== '') {
            $clauses = [];
            if ($query !== '') {
                $like = '%' . $DB->sql_like_escape($query) . '%';
                $clauses[] = $DB->sql_like('u.email', ':qe', false);
                $clauses[] = $DB->sql_like('u.firstname', ':qf', false);
                $clauses[] = $DB->sql_like('u.lastname', ':ql', false);
                $params['qe'] = $like;
                $params['qf'] = $like;
                $params['ql'] = $like;
            }
            if ($reference !== '') {
                // Reference numbers are exact identifiers, so match them exactly.
                $clauses[] = 'a.referencecode = :qref';
                $params['qref'] = $reference;
            }
            $where[] = '(' . implode(' OR ', $clauses) . ')';
        }
        return [implode(' AND ', $where), $params];
    }

    /**
     * Account rows (joined with the core user) for the given users.
     *
     * @param int[] $userids User ids.
     * @return array<int, \stdClass> Keyed by user id.
     */
    public static function get_accounts(array $userids): array {
        global $DB;
        $userids = array_values(array_filter(array_map('intval', $userids)));
        if (!$userids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $sql = "SELECT a.userid, a.id, a.accounttype, a.accountstate, a.timecreated, a.timeexpires,
                       a.referencecode, a.sourcecourseid, u.firstname, u.lastname, u.email, u.suspended
                  FROM {auth_flexaccess_account} a
                  JOIN {user} u ON u.id = a.userid AND u.deleted = 0
                 WHERE a.userid $insql";
        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Temporary accounts that originated in a course (for re-enrolment after an unenrol).
     *
     * @param int $courseid Course id.
     * @return int[] User ids.
     */
    public static function get_source_course_userids(int $courseid): array {
        global $DB;
        return array_map('intval', $DB->get_fieldset_sql(
            "SELECT a.userid
               FROM {auth_flexaccess_account} a
               JOIN {user} u ON u.id = a.userid AND u.deleted = 0
              WHERE a.sourcecourseid = :courseid",
            ['courseid' => $courseid]
        ));
    }
}
