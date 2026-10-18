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
 * Upgrade helpers for auth_flexaccess.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Mark existing temporary access-list accounts as holding an issued, reusable credential.
 *
 * The only source that knows these accounts is tool_flexaccess' member table. Members that were
 * converted are permanent accounts and are left alone; no other account is changed. Idempotent.
 *
 * @return int Number of accounts marked.
 */
function auth_flexaccess_backfill_batchcredential(): int {
    global $DB;
    if (!$DB->get_manager()->table_exists('tool_flexaccess_batch_member')) {
        return 0;
    }
    $userids = $DB->get_fieldset_sql(
        "SELECT a.userid
           FROM {auth_flexaccess_account} a
           JOIN {tool_flexaccess_batch_member} m ON m.userid = a.userid AND m.converted = 0
          WHERE a.accounttype = :type AND a.batchcredential = 0",
        ['type' => 'temporary user']
    );
    foreach (array_chunk(array_map('intval', $userids), 500) as $chunk) {
        [$insql, $params] = $DB->get_in_or_equal($chunk);
        $DB->set_field_select('auth_flexaccess_account', 'batchcredential', 1, "userid $insql", $params);
    }
    return count($userids);
}

/**
 * Withdraw suspension origins that the 2026092202 upgrade step had inferred instead of recorded.
 *
 * That step marked every temporary EXPIRED account with a suspended user as suspended by FlexAccess.
 * The origin of such a legacy suspension cannot be reconstructed: an administrator may have suspended
 * the live account before it expired, which leaves exactly the same data. An inferred mark would let a
 * later repair or recovery lift an administrative suspension, so it is withdrawn; the suspension stays
 * and becomes a review case instead.
 *
 * Only marks the step inferred are withdrawn. It did not touch timemodified, while every real FlexAccess
 * suspension since then went through the lifecycle, which does. So a mark on a row not modified after
 * the step ran is an inferred one. Without a record of that step (fresh install, or the step never ran)
 * there is nothing to withdraw. Idempotent.
 *
 * @return int Number of marks withdrawn.
 */
function auth_flexaccess_withdraw_inferred_lockedby(): int {
    global $DB;
    $steps = $DB->get_records_select(
        'upgrade_log',
        'plugin = :plugin AND version = :version AND info = :info',
        ['plugin' => 'auth_flexaccess', 'version' => '2026092202', 'info' => 'Upgrade savepoint reached'],
        'timemodified DESC',
        'id, timemodified',
        0,
        1
    );
    if (!$steps) {
        return 0;
    }
    $stepran = (int) reset($steps)->timemodified;
    $userids = $DB->get_fieldset_select(
        'auth_flexaccess_account',
        'userid',
        'lockedby = :flexaccess AND timemodified <= :stepran',
        ['flexaccess' => 'flexaccess', 'stepran' => $stepran]
    );
    foreach (array_chunk(array_map('intval', $userids), 500) as $chunk) {
        [$insql, $params] = $DB->get_in_or_equal($chunk);
        $DB->set_field_select('auth_flexaccess_account', 'lockedby', null, "userid $insql", $params);
    }
    return count($userids);
}

/**
 * Register merges tool_mergeusers performed before 2026101700 that involve FlexAccess or LTI identities.
 *
 * Each registered pair is verified by the verify_merges ad-hoc task, so that merged identities whose LTI
 * bindings or grade-delivery pointers still reference the merged-away user surface as review cases.
 * Nothing is changed on the merged identities themselves.
 *
 * @return int Number of registered pairs.
 */
function auth_flexaccess_backfill_merges(): int {
    global $DB;
    $dbman = $DB->get_manager();
    if (!$dbman->table_exists('tool_mergeusers') || !$dbman->table_exists('auth_flexaccess_merge')) {
        return 0;
    }
    $columns = $DB->get_columns('tool_mergeusers');
    if (isset($columns['status'])) {
        $where = "(m.status = 'success' OR m.status IS NULL)";
    } else if (isset($columns['success'])) {
        $where = 'm.success = 1';
    } else {
        $where = '1 = 1';
    }
    $rows = $DB->get_recordset_sql(
        "SELECT DISTINCT m.fromuserid, m.touserid
           FROM {tool_mergeusers} m
          WHERE $where
            AND (EXISTS (SELECT 1 FROM {auth_flexaccess_account} a WHERE a.userid IN (m.fromuserid, m.touserid))
                 OR EXISTS (SELECT 1 FROM {user} u WHERE u.id IN (m.fromuserid, m.touserid) AND u.auth IN ('flexaccess', 'lti'))
                 OR EXISTS (SELECT 1 FROM {auth_lti_linked_login} l WHERE l.userid IN (m.fromuserid, m.touserid)))"
    );
    $now = time();
    $count = 0;
    foreach ($rows as $row) {
        if ($DB->record_exists('auth_flexaccess_merge', ['fromuserid' => $row->fromuserid, 'touserid' => $row->touserid])) {
            continue;
        }
        $DB->insert_record('auth_flexaccess_merge', (object) [
            'fromuserid' => (int) $row->fromuserid,
            'touserid' => (int) $row->touserid,
            'origin' => 'backfill',
            'status' => 'pending',
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $count++;
    }
    $rows->close();
    if ($count > 0) {
        \core\task\manager::queue_adhoc_task(new \auth_flexaccess\task\verify_merges(), true);
    }
    return $count;
}
