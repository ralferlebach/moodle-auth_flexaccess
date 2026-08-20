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
 * Upgrade steps for auth_flexaccess.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade auth_flexaccess.
 *
 * @param int $oldversion The version we are upgrading from.
 * @return bool
 */
function xmldb_auth_flexaccess_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026081901) {
        $table = new xmldb_table('auth_flexaccess_ratehit');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('bucket', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('identifier', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('bucket_identifier_time', XMLDB_INDEX_NOTUNIQUE, ['bucket', 'identifier', 'timecreated']);
        $table->add_index('time_ix', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026081901, 'auth', 'flexaccess');
    }

    if ($oldversion < 2026081912) {
        // The rate-limit identifier is now an HMAC-SHA256 (64 hex chars) instead of SHA1 (40), so
        // widen the column. It participates in the bucket_identifier_time index, which the DB will
        // not let us alter in place, so the index is dropped first and recreated afterwards.
        // Existing SHA1 rows can no longer be matched and are ephemeral, so drop them; counters
        // simply restart from empty. The whole block is idempotent so it survives a retried upgrade.
        $table = new xmldb_table('auth_flexaccess_ratehit');
        $field = new xmldb_field('identifier', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null, 'bucket');
        if ($dbman->table_exists($table) && $dbman->field_exists($table, $field)) {
            $index = new xmldb_index(
                'bucket_identifier_time',
                XMLDB_INDEX_NOTUNIQUE,
                ['bucket', 'identifier', 'timecreated']
            );
            $DB->delete_records('auth_flexaccess_ratehit');
            if ($dbman->index_exists($table, $index)) {
                $dbman->drop_index($table, $index);
            }
            $dbman->change_field_precision($table, $field);
            if (!$dbman->index_exists($table, $index)) {
                $dbman->add_index($table, $index);
            }
        }
        upgrade_plugin_savepoint(true, 2026081912, 'auth', 'flexaccess');
    }

    if ($oldversion < 2026081913) {
        // Performance: a compound index for the expiry/purge scans that filter on
        // accounttype + accountstate + timeexpires.
        $table = new xmldb_table('auth_flexaccess_account');
        $index = new xmldb_index(
            'type_state_expiry_ix',
            XMLDB_INDEX_NOTUNIQUE,
            ['accounttype', 'accountstate', 'timeexpires']
        );
        if ($dbman->table_exists($table) && !$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        upgrade_plugin_savepoint(true, 2026081913, 'auth', 'flexaccess');
    }

    return true;
}
