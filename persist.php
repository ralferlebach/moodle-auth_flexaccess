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
 * Persistence page: a logged-in temporary user upgrades their own account to a permanent one.
 *
 * The conversion keeps the same user id, so all enrolments, results and activity are retained. The
 * user provides a real email, name and password and can then log in again later.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use auth_flexaccess\local\account_service;

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/auth/flexaccess/persist.php'));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('persisttitle', 'auth_flexaccess'));
$PAGE->set_heading(get_string('persisttitle', 'auth_flexaccess'));

$myurl = new moodle_url('/my/');

// Verification link: the token authorises the conversion on its own, so no login is required here.
$token = optional_param('token', '', PARAM_ALPHANUM);
if ($token !== '') {
    $status = \auth_flexaccess\api::confirm_persistence($token);
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('persisttitle', 'auth_flexaccess'));
    if ($status === 'converted') {
        echo $OUTPUT->notification(get_string('persistsuccess', 'auth_flexaccess'), 'success');
    } else if ($status === 'emailtaken') {
        echo $OUTPUT->notification(get_string('registeremailtaken', 'auth_flexaccess'), 'error');
    } else {
        echo $OUTPUT->notification(get_string('persistinvalid', 'auth_flexaccess'), 'error');
    }
    echo $OUTPUT->continue_button(new moodle_url('/login/index.php'));
    echo $OUTPUT->footer();
    exit;
}

require_login();

// Only a current temporary FlexAccess user has anything to persist.
if (!account_service::is_temporary((int) $USER->id)) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('persisttitle', 'auth_flexaccess'));
    echo $OUTPUT->notification(get_string('persistnotapplicable', 'auth_flexaccess'), 'info');
    echo $OUTPUT->continue_button($myurl);
    echo $OUTPUT->footer();
    exit;
}

$form = new \auth_flexaccess\form\persist_form(new moodle_url('/auth/flexaccess/persist.php'));

$failure = null;
if ($form->is_cancelled()) {
    redirect($myurl);
} else if ($data = $form->get_data()) {
    $status = \auth_flexaccess\api::request_persistence(
        (int) $USER->id,
        $data->email,
        $data->firstname,
        $data->lastname,
        $data->password
    );
    if ($status === 'converted') {
        // Refresh the session so the new identity is reflected immediately.
        \core\session\manager::gc();
        $USER = get_complete_user_data('id', $USER->id);
        redirect($myurl, get_string('persistsuccess', 'auth_flexaccess'));
    }
    if ($status === 'verificationsent') {
        echo $OUTPUT->header();
        echo $OUTPUT->heading(get_string('persisttitle', 'auth_flexaccess'));
        echo $OUTPUT->notification(
            get_string('persistverificationsent', 'auth_flexaccess', s($data->email)),
            'success'
        );
        echo $OUTPUT->continue_button($myurl);
        echo $OUTPUT->footer();
        exit;
    }
    $failure = $status;
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('persisttitle', 'auth_flexaccess'));
if ($failure === 'emailtaken') {
    echo $OUTPUT->notification(get_string('registeremailtaken', 'auth_flexaccess'), 'error');
} else if ($failure !== null) {
    echo $OUTPUT->notification(get_string('persistinvalid', 'auth_flexaccess'), 'error');
}
echo html_writer::tag('p', get_string('persistintro', 'auth_flexaccess'));
$form->display();
echo $OUTPUT->footer();
