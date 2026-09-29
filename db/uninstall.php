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
 * Uninstall hook of auth_flexaccess: remove the user preferences FlexAccess set.
 *
 * Core drops the plugin's tables and configuration but leaves user preferences alone.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Uninstall clean-up.
 *
 * @return bool
 */
function xmldb_auth_flexaccess_uninstall() {
    global $DB;
    $DB->delete_records_select(
        'user_preferences',
        $DB->sql_like('name', ':prefix'),
        ['prefix' => $DB->sql_like_escape('auth_flexaccess_') . '%']
    );
    return true;
}
