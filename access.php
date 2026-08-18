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
 * FlexAccess temporary-access entry page.
 *
 * Anonymous entry point: on confirmation it grants temporary access via the enrol controller,
 * establishes the session for the created temporary user and redirects into the course. The
 * redirect target is restricted to a local URL.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// This is a deliberately anonymous entry point; access is gated by the controller, not login.
require_once(__DIR__ . '/../../config.php'); // phpcs:ignore moodle.Files.RequireLogin.Missing

$courseid = optional_param('courseid', 0, PARAM_INT);
$wantsurl = optional_param('wantsurl', '', PARAM_LOCALURL);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

// Login-page links carry only wantsurl; derive the course from it when courseid is absent.
$target = \auth_flexaccess\local\target_resolver::resolve($wantsurl);
if ($courseid <= 0 && $target !== null) {
    $courseid = $target->courseid;
}

// Enumeration guard: do not reveal course existence or name unless the course is visible and
// actually offers an anonymous FlexAccess entry method. Otherwise render a generic notice.
$course = $courseid > 0 ? $DB->get_record('course', ['id' => $courseid]) : false;
$available = $course
    && (int) $course->visible === 1
    && \enrol_flexaccess\api::offers_anonymous_entry($courseid);

if (!$available) {
    $PAGE->set_context(context_system::instance());
    $PAGE->set_url(new moodle_url('/auth/flexaccess/access.php'));
    $PAGE->set_pagelayout('standard');
    $PAGE->set_title(get_string('access:title', 'auth_flexaccess'));
    $PAGE->set_heading(get_string('access:title', 'auth_flexaccess'));
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('access:title', 'auth_flexaccess'));
    echo $OUTPUT->notification(get_string('access:unavailable', 'auth_flexaccess'), 'error');
    echo $OUTPUT->continue_button(new moodle_url('/login/index.php'));
    echo $OUTPUT->footer();
    exit;
}

$courseurl = new moodle_url('/course/view.php', ['id' => $courseid]);
$returnurl = $target !== null ? $target->redirect_url() : $courseurl;

$PAGE->set_context(context_course::instance($courseid));
$PAGE->set_url(new moodle_url('/auth/flexaccess/access.php', ['courseid' => $courseid]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('access:title', 'auth_flexaccess'));
$PAGE->set_heading(format_string($course->fullname));

// A real authenticated user has nothing to do here.
if (isloggedin() && !isguestuser()) {
    redirect($returnurl);
}

$failure = null;
if ($confirm && confirm_sesskey()) {
    $result = \enrol_flexaccess\local\access_controller::grant_temporary_access($courseid);
    if ($result->status === 'granted') {
        $user = $DB->get_record('user', ['id' => $result->userid], '*', MUST_EXIST);
        complete_user_login($user);
        redirect($returnurl, get_string('access:granted', 'auth_flexaccess'));
    }
    $failure = $result->status;
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('access:title', 'auth_flexaccess'));

if ($failure !== null) {
    echo $OUTPUT->notification(get_string('access:' . $failure, 'auth_flexaccess'), 'error');
    echo $OUTPUT->continue_button($courseurl);
} else {
    $continueurl = new moodle_url(
        '/auth/flexaccess/access.php',
        ['courseid' => $courseid, 'wantsurl' => $wantsurl, 'confirm' => 1, 'sesskey' => sesskey()]
    );
    echo $OUTPUT->confirm(
        get_string('access:intro', 'auth_flexaccess', format_string($course->fullname)),
        $continueurl,
        $courseurl
    );
}

echo $OUTPUT->footer();
