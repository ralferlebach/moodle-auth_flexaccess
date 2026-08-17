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
 * Persistence landing page: converts the current temporary user to an authenticated user.
 *
 * The single-use token authorises exactly one user id; the link only works for the matching
 * logged-in user. Enrolment and its duration are never changed by conversion.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use auth_flexaccess\local\account_service;
use auth_flexaccess\local\token_service;

$token = required_param('token', PARAM_ALPHANUM);

require_login();
$context = context_system::instance();

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/auth/flexaccess/persist.php'));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('persist:title', 'auth_flexaccess'));
$PAGE->set_heading(get_string('persist:title', 'auth_flexaccess'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('persist:title', 'auth_flexaccess'));

$record = token_service::verify($token, 'persistence');
if (!$record || (int) $record->userid !== (int) $USER->id) {
    echo $OUTPUT->notification(get_string('persist:invalid', 'auth_flexaccess'), 'error');
} else {
    token_service::consume($token, 'persistence');
    account_service::convert_to_authenticated((int) $USER->id);
    echo $OUTPUT->notification(get_string('persist:success', 'auth_flexaccess'), 'success');
}

echo $OUTPUT->continue_button(new moodle_url('/my/'));
echo $OUTPUT->footer();
