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
 * Plugin version definition for auth_flexaccess.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'auth_flexaccess';
$plugin->version = 2026102600;
$plugin->requires = 2024100700; // Moodle 4.5.
$plugin->supported = [405, 503];
$plugin->maturity = MATURITY_STABLE;
$plugin->release = '1.2.0';
// No declared dependency on enrol_flexaccess, although auth_flexaccess needs it at run time (the access-method
// policy lives there): enrol_flexaccess requires auth_flexaccess, and a declared dependency in both directions is a
// cycle that Moodle cannot uninstall - neither plugin could ever be removed (enrol_flexaccess #15). The family is
// installed together (tool_flexaccess and mod_flexaccess require both); the system status of tool_flexaccess
// reports a missing sibling.
$plugin->dependencies = [];
