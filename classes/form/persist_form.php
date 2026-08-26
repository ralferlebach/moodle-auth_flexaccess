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

namespace auth_flexaccess\form;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/formslib.php');

/**
 * Persistence form: a temporary user gives their account a real identity so it survives.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class persist_form extends \moodleform {
    use identity_fields;

    /**
     * Form definition.
     *
     * @return void
     */
    protected function definition(): void {
        $this->add_identity_fields();
        $this->add_action_buttons(true, get_string('persistsubmit', 'auth_flexaccess'));
    }

    /**
     * Server-side validation: valid, unused email and a password meeting the site policy.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array Field errors.
     */
    public function validation($data, $files): array {
        global $USER;
        return array_merge(parent::validation($data, $files), $this->validate_identity($data, (int) $USER->id));
    }
}
