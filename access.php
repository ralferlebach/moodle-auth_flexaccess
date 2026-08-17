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

require_once(__DIR__ . '/../../config.php');

$courseid = required_param('courseid', PARAM_INT);
$wantsurl = optional_param('wantsurl', '', PARAM_LOCALURL);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

$course = get_course($courseid);
$courseurl = new moodle_url('/course/view.php', ['id' => $courseid]);
$returnurl = $wantsurl !== '' ? new moodle_url($wantsurl) : $courseurl;

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
    $continueurl = new moodle_url('/auth/flexaccess/access.php',
        ['courseid' => $courseid, 'wantsurl' => $wantsurl, 'confirm' => 1, 'sesskey' => sesskey()]);
    echo $OUTPUT->confirm(
        get_string('access:intro', 'auth_flexaccess', format_string($course->fullname)),
        $continueurl, $courseurl);
}

echo $OUTPUT->footer();
