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
 * Event observers of auth_flexaccess.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        // A credential set anywhere (e.g. Moodle's forgotten-password flow) finalises a pending account.
        'eventname' => '\core\event\user_password_updated',
        'callback' => '\auth_flexaccess\observer::user_password_updated',
    ],
    [
        // Identity merges performed by tool_mergeusers are reconciled; the event only fires when that
        // plugin is installed.
        'eventname' => '\tool_mergeusers\event\user_merged_success',
        'callback' => '\auth_flexaccess\observer::user_merged',
    ],
];
