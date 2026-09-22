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
