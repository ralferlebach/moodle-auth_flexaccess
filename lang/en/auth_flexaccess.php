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
 * Language strings for auth_flexaccess.
 *
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'FlexAccess authentication';
$string['accessprovider'] = 'Flexible course access';
$string['stubnotice'] = 'FlexAccess scaffold: target-aware login is not enabled yet.';
$string['settings:accounts'] = 'Accounts';
$string['settings:mail'] = 'E-mail queue';
$string['temporarylifetime'] = 'Default temporary-user lifetime';
$string['temporarylifetime_desc'] = 'Default account lifetime for anonymous temporary users. Course policy may further restrict it.';
$string['provisionallifetime'] = 'Default provisional lifetime';
$string['provisionallifetime_desc'] = 'Time allowed to verify a quick registration.';
$string['senderemail'] = 'Optional sender e-mail address';
$string['senderemail_desc'] = 'Leave empty to use the Moodle default sender.';
$string['maillimitperhour'] = 'Maximum FlexAccess e-mails per rolling hour';
$string['maillimitperhour_desc'] = 'Limits only messages sent by FlexAccess. Zero/unlimited disables the FlexAccess throttle.';
$string['unlimited'] = 'Unlimited';
$string['task:processmailqueue'] = 'Process FlexAccess mail queue';
$string['task:expireaccounts'] = 'Expire temporary FlexAccess accounts';
$string['privacy:metadata:account'] = 'FlexAccess account lifecycle metadata.';
$string['privacy:metadata:account:userid'] = 'The Moodle user ID.';
$string['privacy:metadata:account:accounttype'] = 'Whether this is a temporary user or authenticated user.';
$string['privacy:metadata:account:accountstate'] = 'The FlexAccess lifecycle state.';
$string['privacy:metadata:account:referencecode'] = 'Administrative reference code.';
$string['privacy:metadata:token'] = 'Metadata for one-time account tokens.';
$string['privacy:metadata:token:userid'] = 'The Moodle user ID associated with the token.';
$string['privacy:metadata:token:purpose'] = 'The token purpose.';
$string['privacy:metadata:mail'] = 'Queued FlexAccess e-mail metadata.';
$string['privacy:metadata:mail:userid'] = 'The associated Moodle user ID, if available.';
$string['privacy:metadata:mail:recipient'] = 'The e-mail recipient.';
$string['privacy:metadata:mail:mailtype'] = 'The semantic mail type.';
