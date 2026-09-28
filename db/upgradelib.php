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
