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

namespace auth_flexaccess\local;

/**
 * Two-step handling of single-use links that arrive by e-mail.
 *
 * Opening a link (GET) must not change anything: mail security scanners and link previewers fetch
 * links before the person does. A GET only shows a confirmation page; the token is spent by the
 * POST the person sends with the button, protected by the session key.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class link_confirmation {
    /**
     * Whether this request is the person's confirmation (POST with a valid session key).
     *
     * @return bool
     */
    public static function is_confirmed(): bool {
        return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
            && optional_param('confirmlink', 0, PARAM_BOOL)
            && confirm_sesskey();
    }

    /**
     * Render the confirmation page for a link token (the token itself is not spent here).
     *
     * @param \moodle_url $action Page the confirmation is posted to.
     * @param string $token The link token.
     * @param string $heading Page heading.
     * @param string $intro Explanation above the button.
     * @param string $button Button label.
     * @return string HTML of the whole page.
     */
    public static function render(\moodle_url $action, string $token, string $heading, string $intro, string $button): string {
        global $OUTPUT;
        $html = $OUTPUT->header();
        $html .= $OUTPUT->heading($heading);
        $html .= \html_writer::tag('p', $intro);
        $html .= \html_writer::start_tag('form', ['method' => 'post', 'action' => $action->out(false)]);
        $html .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'token', 'value' => $token]);
        $html .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'confirmlink', 'value' => 1]);
        $html .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        $html .= \html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-primary', 'value' => $button]);
        $html .= \html_writer::end_tag('form');
        $html .= $OUTPUT->footer();
        return $html;
    }
}
