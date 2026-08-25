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

// State-changing actions on this page (guest login, temporary-account creation) must arrive by POST
// so that a GET prefetch, security scanner or link-preview fetch can never trigger them.
$ispost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';

// Seed the post-login return target server-side rather than trusting a wantsurl query parameter,
// which Moodle 4.5 does not reliably honour on the normal login path.
$SESSION->wantsurl = $returnurl->out_as_local_url(false);

// Guest access: log in as the Moodle guest user and continue to the course. Whether the guest can
// then view content depends on the course's own guest enrolment, which FlexAccess does not manage.
if (
    optional_param('guest', 0, PARAM_BOOL) && $ispost && confirm_sesskey()
        && \enrol_flexaccess\api::offers_guest_access($courseid)
) {
    $guestuser = get_complete_user_data('username', 'guest');
    if ($guestuser) {
        complete_user_login($guestuser);
    }
    redirect($returnurl);
}

$keyrequired = \enrol_flexaccess\api::requires_temporary_access_key($courseid);
$quickreglink = \enrol_flexaccess\api::offers_quick_registration($courseid)
    ? html_writer::tag('p', html_writer::link(
        new moodle_url('/auth/flexaccess/register.php', ['courseid' => $courseid, 'wantsurl' => $wantsurl]),
        get_string('access:orregister', 'auth_flexaccess')
    ))
    : '';
$loginlink = \enrol_flexaccess\api::offers_normal_login($courseid)
    ? html_writer::tag('p', html_writer::link(
        new moodle_url('/login/index.php'),
        get_string('access:orlogin', 'auth_flexaccess')
    ))
    : '';
$guestlink = \enrol_flexaccess\api::offers_guest_access($courseid)
    ? html_writer::tag('div', $OUTPUT->single_button(
        new moodle_url(
            '/auth/flexaccess/access.php',
            ['courseid' => $courseid, 'guest' => 1, 'sesskey' => sesskey()]
        ),
        get_string('access:orguest', 'auth_flexaccess'),
        'post'
    ))
    : '';
$magiclink = \auth_flexaccess\api::magic_login_enabled()
    ? html_writer::tag('p', html_writer::link(
        new moodle_url('/auth/flexaccess/magic.php'),
        get_string('access:ormagic', 'auth_flexaccess')
    ))
    : '';
$rateid = \enrol_flexaccess\local\access_key_rate::identifier(getremoteaddr(), $courseid);

$failure = null;
if ($confirm && $ispost && confirm_sesskey()) {
    // The key arrives as a POST field, so it never appears in the URL, referrer or server logs.
    $accesskey = $keyrequired ? optional_param('accesskey', '', PARAM_RAW) : null;
    if ($keyrequired && \enrol_flexaccess\local\access_key_rate::is_blocked($rateid)) {
        $failure = 'keyblocked';
    } else {
        $result = \enrol_flexaccess\local\access_controller::grant_temporary_access(
            $courseid,
            null,
            $accesskey,
            getremoteaddr()
        );
        if ($result->status === 'granted') {
            \enrol_flexaccess\local\access_key_rate::reset($rateid);
            $user = $DB->get_record('user', ['id' => $result->userid], '*', MUST_EXIST);
            complete_user_login($user);
            redirect($returnurl, get_string('access:granted', 'auth_flexaccess'));
        }
        if ($result->status === 'badkey') {
            \enrol_flexaccess\local\access_key_rate::record_failure($rateid);
        }
        $failure = $result->status;
    }
}

// Build the temporary-access entry (key challenge or plain confirmation) shown in the left column.
$temporaryentry = '';
if ($keyrequired) {
    // Render a challenge form: the key is submitted by POST and verified server-side before any
    // account is created.
    if ($failure === 'badkey') {
        $temporaryentry .= $OUTPUT->notification(get_string('access:badkey', 'auth_flexaccess'), 'error');
    }
    $temporaryentry .= html_writer::start_tag('form', [
        'method' => 'post',
        'action' => (new moodle_url('/auth/flexaccess/access.php'))->out(false),
    ]);
    $temporaryentry .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $courseid]);
    $temporaryentry .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'wantsurl', 'value' => $wantsurl]);
    $temporaryentry .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'confirm', 'value' => 1]);
    $temporaryentry .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    $temporaryentry .= html_writer::tag('label', get_string('access:enterkey', 'auth_flexaccess'), ['for' => 'flexaccesskey']);
    $temporaryentry .= html_writer::empty_tag('input', [
        'type' => 'password',
        'id' => 'flexaccesskey',
        'name' => 'accesskey',
        'autocomplete' => 'off',
        'class' => 'form-control',
    ]);
    $temporaryentry .= html_writer::empty_tag('input', [
        'type' => 'submit',
        'value' => get_string('continue'),
        'class' => 'btn btn-primary mt-2',
    ]);
    $temporaryentry .= html_writer::end_tag('form');
} else {
    // No key required: a POST-only confirmation button creates the temporary account. Using POST
    // (not a GET link) keeps prefetch/scanners from creating accounts.
    $continueurl = new moodle_url(
        '/auth/flexaccess/access.php',
        ['courseid' => $courseid, 'confirm' => 1, 'sesskey' => sesskey()]
    );
    $temporaryentry .= $OUTPUT->single_button($continueurl, get_string('continue'), 'post');
    $temporaryentry .= $OUTPUT->action_link($courseurl, get_string('cancel'));
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('access:title', 'auth_flexaccess'));

if ($failure !== null && $failure !== 'badkey') {
    // Hard failure (e.g. rate-limited): keep it simple and full width, no entry options.
    echo $OUTPUT->notification(get_string('access:' . $failure, 'auth_flexaccess'), 'error');
    echo $OUTPUT->continue_button($courseurl);
} else {
    // Two-column entry, mirroring the familiar Moodle "alternate login" layout: on the left the
    // no-account path (temporary account, guest), on the right the account-based path (log in,
    // register, email link). Columns stack on narrow screens via Bootstrap's grid.
    echo html_writer::start_div('row flexaccess-entry');

    echo html_writer::start_div('col-md-6 flexaccess-entry-temporary');
    echo $OUTPUT->heading(get_string('access:coltemporary', 'auth_flexaccess'), 3);
    echo html_writer::tag('p', get_string('access:intro', 'auth_flexaccess', format_string($course->fullname)));
    echo $temporaryentry;
    echo $guestlink;
    echo html_writer::end_div();

    $accountside = $loginlink . $quickreglink . $magiclink;
    echo html_writer::start_div('col-md-6 flexaccess-entry-account');
    echo $OUTPUT->heading(get_string('access:colaccount', 'auth_flexaccess'), 3);
    echo $accountside !== ''
        ? $accountside
        : html_writer::tag('p', get_string('access:noaccountoptions', 'auth_flexaccess'));
    echo html_writer::end_div();

    echo html_writer::end_div();
}

echo $OUTPUT->footer();
