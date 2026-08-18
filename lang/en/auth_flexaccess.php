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
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['access:closed'] = 'Temporary access is not available at this time.';
$string['access:full'] = 'The maximum number of participants has been reached. Please try again later.';
$string['access:granted'] = 'Temporary access granted. Welcome!';
$string['access:intro'] = 'You are about to get temporary access to "{$a}". Your progress can later be kept by activating your account.';
$string['access:notallowed'] = 'Temporary access is not offered for this course.';
$string['access:notenabled'] = 'FlexAccess is not enabled for this course.';
$string['access:title'] = 'Temporary course access';
$string['access:unavailable'] = 'This course is not available for FlexAccess entry.';
$string['accessprovider'] = 'Flexible course access';
$string['flexaccess:convertaccounts'] = 'Convert FlexAccess accounts to authenticated users';
$string['flexaccess:manageaccounts'] = 'Manage FlexAccess accounts';
$string['followup:body'] = 'You accessed a course with a temporary FlexAccess account. To keep your results and turn it into a permanent account, open this link: {$a}';
$string['followup:subject'] = 'Keep your results: activate your account';
$string['maillimitperhour'] = 'Maximum FlexAccess e-mails per rolling hour';
$string['maillimitperhour_desc'] = 'Limits only messages sent by FlexAccess. Zero/unlimited disables the FlexAccess throttle.';
$string['mailsendfailed'] = 'FlexAccess could not send the follow-up email.';
$string['persist:invalid'] = 'This activation link is invalid, has expired, or was already used.';
$string['persist:success'] = 'Your account is now permanent. Your results and access are kept.';
$string['persist:title'] = 'Activate your account';
$string['pluginname'] = 'FlexAccess authentication';
$string['privacy:metadata:account'] = 'FlexAccess account lifecycle metadata.';
$string['privacy:metadata:account:accountstate'] = 'The FlexAccess lifecycle state.';
$string['privacy:metadata:account:accounttype'] = 'Whether this is a temporary user or authenticated user.';
$string['privacy:metadata:account:referencecode'] = 'Administrative reference code.';
$string['privacy:metadata:account:userid'] = 'The Moodle user ID.';
$string['privacy:metadata:mail'] = 'Queued FlexAccess e-mail metadata.';
$string['privacy:metadata:mail:mailtype'] = 'The semantic mail type.';
$string['privacy:metadata:mail:recipient'] = 'The e-mail recipient.';
$string['privacy:metadata:mail:userid'] = 'The associated Moodle user ID, if available.';
$string['privacy:metadata:token'] = 'Metadata for one-time account tokens.';
$string['privacy:metadata:token:purpose'] = 'The token purpose.';
$string['privacy:metadata:token:userid'] = 'The Moodle user ID associated with the token.';
$string['provisionallifetime'] = 'Default provisional lifetime';
$string['provisionallifetime_desc'] = 'Time allowed to verify a quick registration.';
$string['senderemail'] = 'Optional sender e-mail address';
$string['senderemail_desc'] = 'Leave empty to use the Moodle default sender.';
$string['settings:accounts'] = 'Accounts';
$string['settings:mail'] = 'E-mail queue';
$string['stubnotice'] = 'FlexAccess scaffold: target-aware login is not enabled yet.';
$string['task:expireaccounts'] = 'Expire temporary FlexAccess accounts';
$string['task:processmailqueue'] = 'Process FlexAccess mail queue';
$string['temporaryfirstname'] = 'FlexAccess';
$string['temporarylastname'] = 'Guest';
$string['temporarylifetime'] = 'Default temporary-user lifetime';
$string['temporarylifetime_desc'] = 'Default account lifetime for anonymous temporary users. Course policy may further restrict it.';
$string['unlimited'] = 'Unlimited';
