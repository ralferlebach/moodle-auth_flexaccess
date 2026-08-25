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
 * Passwordless magic-login page.
 *
 * With a token: consumes it and logs the user in. Without a token: shows a form to request a
 * one-time login link, which is queued to the pending address. The request response never reveals
 * whether an account exists for the given address.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php'); // phpcs:ignore moodle.Files.RequireLogin.Missing

$token = optional_param('token', '', PARAM_ALPHANUM);

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/auth/flexaccess/magic.php'));
$PAGE->set_pagelayout('login');
$PAGE->set_title(get_string('magic:title', 'auth_flexaccess'));
$PAGE->set_heading(get_string('magic:title', 'auth_flexaccess'));

$loginurl = new moodle_url('/login/index.php');

// Token mode: the link authorises the login on its own.
if ($token !== '') {
    $userid = \auth_flexaccess\api::consume_magic_login($token);
    if ($userid !== null) {
        $user = get_complete_user_data('id', $userid);
        complete_user_login($user);
        redirect(new moodle_url('/my/'), get_string('magic:success', 'auth_flexaccess'));
    }
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('magic:title', 'auth_flexaccess'));
    echo $OUTPUT->notification(get_string('magic:invalid', 'auth_flexaccess'), 'error');
    echo $OUTPUT->continue_button(new moodle_url('/auth/flexaccess/magic.php'));
    echo $OUTPUT->footer();
    exit;
}

// A logged-in user does not need a magic link.
if (isloggedin() && !isguestuser()) {
    redirect(new moodle_url('/my/'));
}

if (!\auth_flexaccess\api::magic_login_enabled()) {
    redirect($loginurl, get_string('magic:disabled', 'auth_flexaccess'), null, \core\output\notification::NOTIFY_INFO);
}

// Direct POST from the inline email form on access.php (sesskey-protected), so the request can be
// made straight from the FlexAccess entry page without an intermediate form.
$directemail = optional_param('email', '', PARAM_RAW_TRIMMED);
if ($directemail !== '' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && confirm_sesskey()) {
    \auth_flexaccess\api::request_magic_login($directemail, getremoteaddr());
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('magic:title', 'auth_flexaccess'));
    echo $OUTPUT->notification(get_string('magic:sent', 'auth_flexaccess', s($directemail)), 'success');
    echo $OUTPUT->continue_button($loginurl);
    echo $OUTPUT->footer();
    exit;
}

$form = new \auth_flexaccess\form\magic_login_form(new moodle_url('/auth/flexaccess/magic.php'));
if ($form->is_cancelled()) {
    redirect($loginurl);
} else if ($data = $form->get_data()) {
    \auth_flexaccess\api::request_magic_login($data->email, getremoteaddr());
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('magic:title', 'auth_flexaccess'));
    echo $OUTPUT->notification(get_string('magic:sent', 'auth_flexaccess', s($data->email)), 'success');
    echo $OUTPUT->continue_button($loginurl);
    echo $OUTPUT->footer();
    exit;
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('magic:title', 'auth_flexaccess'));
echo html_writer::tag('p', get_string('magic:intro', 'auth_flexaccess'));
$form->display();
echo $OUTPUT->footer();
