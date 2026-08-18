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
 * FlexAccess quick-registration entry page.
 *
 * Anonymous entry point: creates a persistent account with minimal detail and enrols it. The
 * redirect target is restricted to a local URL resolved from wantsurl.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// This is a deliberately anonymous entry point; access is gated by the controller, not login.
require_once(__DIR__ . '/../../config.php'); // phpcs:ignore moodle.Files.RequireLogin.Missing

$courseid = optional_param('courseid', 0, PARAM_INT);
$wantsurl = optional_param('wantsurl', '', PARAM_LOCALURL);

$target = \auth_flexaccess\local\target_resolver::resolve($wantsurl);
if ($courseid <= 0 && $target !== null) {
    $courseid = $target->courseid;
}

// Enumeration guard: only reveal the course when it is visible and offers quick registration.
$course = $courseid > 0 ? $DB->get_record('course', ['id' => $courseid]) : false;
$available = $course
    && (int) $course->visible === 1
    && \enrol_flexaccess\api::offers_quick_registration($courseid);

if (!$available) {
    $PAGE->set_context(context_system::instance());
    $PAGE->set_url(new moodle_url('/auth/flexaccess/register.php'));
    $PAGE->set_pagelayout('standard');
    $PAGE->set_title(get_string('register:title', 'auth_flexaccess'));
    $PAGE->set_heading(get_string('register:title', 'auth_flexaccess'));
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('register:title', 'auth_flexaccess'));
    echo $OUTPUT->notification(get_string('access:unavailable', 'auth_flexaccess'), 'error');
    echo $OUTPUT->continue_button(new moodle_url('/login/index.php'));
    echo $OUTPUT->footer();
    exit;
}

$courseurl = new moodle_url('/course/view.php', ['id' => $courseid]);
$returnurl = $target !== null ? $target->redirect_url() : $courseurl;

$PAGE->set_context(context_course::instance($courseid));
$PAGE->set_url(new moodle_url('/auth/flexaccess/register.php', ['courseid' => $courseid]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('register:title', 'auth_flexaccess'));
$PAGE->set_heading(format_string($course->fullname));

// A real authenticated user has nothing to do here.
if (isloggedin() && !isguestuser()) {
    redirect($returnurl);
}

$form = new \auth_flexaccess\form\quick_registration_form(
    new moodle_url('/auth/flexaccess/register.php'),
    ['courseid' => $courseid, 'wantsurl' => $wantsurl]
);

$failure = null;
if ($form->is_cancelled()) {
    redirect($courseurl);
} else if ($data = $form->get_data()) {
    $result = \enrol_flexaccess\local\access_controller::grant_quick_registration($courseid, (object) [
        'email' => $data->email,
        'firstname' => $data->firstname,
        'lastname' => $data->lastname,
        'password' => $data->password,
    ], getremoteaddr());
    if ($result->status === 'granted') {
        $user = $DB->get_record('user', ['id' => $result->userid], '*', MUST_EXIST);
        complete_user_login($user);
        redirect($returnurl, get_string('register:success', 'auth_flexaccess'));
    }
    $failure = $result->status;
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('register:title', 'auth_flexaccess'));
if ($failure !== null) {
    echo $OUTPUT->notification(get_string('access:' . $failure, 'auth_flexaccess'), 'error');
}
echo html_writer::tag('p', get_string('register:intro', 'auth_flexaccess', format_string($course->fullname)));
$form->display();
echo $OUTPUT->footer();
