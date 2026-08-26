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
 * Set-password landing page for an administrator-converted FlexAccess account.
 *
 * The visitor arrives via a tokenised link mailed through the FlexAccess mail queue. The token is
 * verified for display and only consumed when a valid password is submitted, after which the user is
 * logged in.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php'); // phpcs:ignore moodle.Files.RequireLogin.Missing

$token = required_param('token', PARAM_ALPHANUM);

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/auth/flexaccess/setpassword.php'));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('setpasswordtitle', 'auth_flexaccess'));
$PAGE->set_heading(get_string('setpasswordtitle', 'auth_flexaccess'));

$loginurl = new moodle_url('/login/index.php');
$homeurl = new moodle_url('/');

$form = new \auth_flexaccess\form\set_password_form(new moodle_url('/auth/flexaccess/setpassword.php'));

if ($form->is_cancelled()) {
    redirect($loginurl);
} else if ($data = $form->get_data()) {
    $userid = \auth_flexaccess\api::complete_set_password($data->token, $data->password);
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('setpasswordtitle', 'auth_flexaccess'));
    if ($userid !== null) {
        $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
        complete_user_login($user);
        redirect($homeurl, get_string('setpasswordsuccess', 'auth_flexaccess'));
    }
    echo $OUTPUT->notification(get_string('setpasswordinvalid', 'auth_flexaccess'), 'error');
    echo $OUTPUT->continue_button($loginurl);
    echo $OUTPUT->footer();
    exit;
}

// Initial display: verify the token without consuming it.
$valid = \auth_flexaccess\api::begin_set_password($token) !== null;

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('setpasswordtitle', 'auth_flexaccess'));
if (!$valid) {
    echo $OUTPUT->notification(get_string('setpasswordinvalid', 'auth_flexaccess'), 'error');
    echo $OUTPUT->continue_button($loginurl);
    echo $OUTPUT->footer();
    exit;
}
echo html_writer::tag('p', get_string('setpasswordintro', 'auth_flexaccess'));
$form->set_data(['token' => $token]);
$form->display();
echo $OUTPUT->footer();
