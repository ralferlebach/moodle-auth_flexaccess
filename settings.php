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
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_heading(
        'auth_flexaccess/accounts',
        get_string('settingsaccounts', 'auth_flexaccess'),
        get_string('settingsaccounts_desc', 'auth_flexaccess')
    ));
    $settings->add(new admin_setting_heading(
        'auth_flexaccess/mail',
        get_string('settingsmail', 'auth_flexaccess'),
        ''
    ));
    $settings->add(new admin_setting_configcheckbox(
        'auth_flexaccess/requireemailverification',
        get_string('settingrequireemailverification', 'auth_flexaccess'),
        get_string('settingrequireemailverification_desc', 'auth_flexaccess'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'auth_flexaccess/allowmagiclogin',
        get_string('settingallowmagiclogin', 'auth_flexaccess'),
        get_string('settingallowmagiclogin_desc', 'auth_flexaccess'),
        1
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

    $settings->add(new admin_setting_configtext(
        'auth_flexaccess/retentiondays',
        get_string('settingretentiondays', 'auth_flexaccess'),
        get_string('settingretentiondays_desc', 'auth_flexaccess'),
        30,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configduration(
        'auth_flexaccess/followupwindow',
        get_string('settingfollowupwindow', 'auth_flexaccess'),
        get_string('settingfollowupwindow_desc', 'auth_flexaccess'),
        DAYSECS
    ));

    $settings->add(new admin_setting_heading(
        'auth_flexaccess/ratelimit',
        get_string('settingsratelimit', 'auth_flexaccess'),
        get_string('settingsratelimit_desc', 'auth_flexaccess')
    ));
    $settings->add(new admin_setting_configtext(
        'auth_flexaccess/magicmaxperip',
        get_string('settingmagicmaxperip', 'auth_flexaccess'),
        get_string('settingmagicmaxperip_desc', 'auth_flexaccess'),
        15,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        'auth_flexaccess/magicmaxperemail',
        get_string('settingmagicmaxperemail', 'auth_flexaccess'),
        get_string('settingmagicmaxperemail_desc', 'auth_flexaccess'),
        3,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        'auth_flexaccess/magicwindow',
        get_string('settingmagicwindow', 'auth_flexaccess'),
        get_string('settingmagicwindow_desc', 'auth_flexaccess'),
        600,
        PARAM_INT
    ));
}
