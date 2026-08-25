<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Library callbacks for auth_flexaccess.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Show temporary users a hint on how to make their access permanent (D2).
 *
 * Deliberately scoped: only on a real course page, only for FlexAccess temporary accounts, and only
 * in courses that actually contain a FlexAccess activity (mod_flexaccess) - so the prompt appears
 * where persisting progress is meaningful and nowhere else.
 *
 * @return string HTML injected at the top of the body (empty when not applicable).
 */
function auth_flexaccess_before_standard_top_of_body_html() {
    global $PAGE, $USER, $DB;

    if (!isloggedin() || isguestuser()) {
        return '';
    }
    if (empty($PAGE->course) || (int) $PAGE->course->id <= SITEID) {
        return '';
    }
    $courseid = (int) $PAGE->course->id;
    if (!$DB->record_exists('flexaccess', ['course' => $courseid])) {
        return '';
    }
    if (!\auth_flexaccess\local\account_service::is_temporary((int) $USER->id)) {
        return '';
    }

    $link = html_writer::link(
        new moodle_url('/auth/flexaccess/persist.php'),
        get_string('persisthint:cta', 'auth_flexaccess'),
        ['class' => 'alert-link']
    );
    return html_writer::div(
        get_string('persisthint:text', 'auth_flexaccess') . ' ' . $link,
        'alert alert-info flexaccess-persist-hint'
    );
}
