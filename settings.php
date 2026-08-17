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
 * Administration settings for auth_flexaccess.
 *
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_heading(
        'auth_flexaccess/accounts',
        get_string('settings:accounts', 'auth_flexaccess'),
        ''
    ));
    $settings->add(new admin_setting_configselect(
        'auth_flexaccess/temporarylifetime',
        get_string('temporarylifetime', 'auth_flexaccess'),
        get_string('temporarylifetime_desc', 'auth_flexaccess'),
        21600,
        [
            10800 => '3 h', 21600 => '6 h', 43200 => '12 h', 86400 => '24 h',
            0 => get_string('unlimited', 'auth_flexaccess'),
        ]
    ));
    $settings->add(new admin_setting_configselect(
        'auth_flexaccess/provisionallifetime',
        get_string('provisionallifetime', 'auth_flexaccess'),
        get_string('provisionallifetime_desc', 'auth_flexaccess'),
        172800,
        [86400 => '24 h', 172800 => '48 h', 604800 => '1 week', 0 => get_string('unlimited', 'auth_flexaccess')]
    ));
    $settings->add(new admin_setting_heading(
        'auth_flexaccess/mail',
        get_string('settings:mail', 'auth_flexaccess'),
        ''
    ));
    $settings->add(new admin_setting_configtext(
        'auth_flexaccess/senderemail',
        get_string('senderemail', 'auth_flexaccess'),
        get_string('senderemail_desc', 'auth_flexaccess'),
        '',
        PARAM_EMAIL
    ));
    $settings->add(new admin_setting_configselect(
        'auth_flexaccess/maillimitperhour',
        get_string('maillimitperhour', 'auth_flexaccess'),
        get_string('maillimitperhour_desc', 'auth_flexaccess'),
        100,
        [10 => '10', 50 => '50', 100 => '100', 500 => '500', 0 => get_string('unlimited', 'auth_flexaccess')]
    ));
}
