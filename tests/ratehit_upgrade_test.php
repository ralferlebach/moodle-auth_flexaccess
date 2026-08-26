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

namespace auth_flexaccess;


/**
 * Guards the ratehit.identifier widen upgrade (SHA1/40 -> HMAC-SHA256/64) against the
 * index-dependency failure seen on real MySQL/MariaDB upgrades.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \xmldb_auth_flexaccess_upgrade
 */
final class ratehit_upgrade_test extends \advanced_testcase {
    /**
     * Widening the indexed identifier column requires dropping and recreating the index; doing it
     * naively raises a dependency exception (the production upgrade failure this reproduces).
     *
     * @return void
     */
    public function test_widen_indexed_identifier(): void {
        global $DB;
        $this->resetAfterTest();
        $dbman = $DB->get_manager();

        $table = new \xmldb_table('auth_flexaccess_ratehit');
        $index = new \xmldb_index(
            'bucket_identifier_time',
            XMLDB_INDEX_NOTUNIQUE,
            ['bucket', 'identifier', 'timecreated']
        );
        $field40 = new \xmldb_field('identifier', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, null, 'bucket');
        $field64 = new \xmldb_field('identifier', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null, 'bucket');

        // Recreate the pre-0.9.12 state: a 40-char identifier participating in the index.
        if ($dbman->index_exists($table, $index)) {
            $dbman->drop_index($table, $index);
        }
        $dbman->change_field_precision($table, $field40);
        $dbman->add_index($table, $index);
        $columns = $DB->get_columns('auth_flexaccess_ratehit', false);
        $this->assertSame(40, (int) $columns['identifier']->max_length);

        // The naive widen (as originally shipped) hits the index dependency and fails.
        $threw = false;
        try {
            $dbman->change_field_precision($table, $field64);
        } catch (\ddl_dependency_exception $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'The naive widen should fail on the indexed column.');

        // The fixed sequence: drop the index, widen, recreate the index.
        if ($dbman->index_exists($table, $index)) {
            $dbman->drop_index($table, $index);
        }
        $dbman->change_field_precision($table, $field64);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        $columns = $DB->get_columns('auth_flexaccess_ratehit', false);
        $this->assertSame(64, (int) $columns['identifier']->max_length);
        $this->assertTrue($dbman->index_exists($table, $index));
    }
}
