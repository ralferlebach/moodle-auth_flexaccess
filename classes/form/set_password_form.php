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

require_once($CFG->libdir . '/formslib.php');

/**
 * Set-password form shown after an administrator converts a temporary account.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class set_password_form extends \moodleform {
    /**
     * Form definition.
     *
     * @return void
     */
    protected function definition(): void {
        $mform = $this->_form;

        $mform->addElement('hidden', 'token');
        $mform->setType('token', PARAM_ALPHANUM);

        $mform->addElement('passwordunmask', 'password', get_string('setpasswordpassword', 'auth_flexaccess'));
        $mform->setType('password', PARAM_RAW);
        $mform->addRule('password', get_string('required'), 'required', null, 'client');

        $mform->addElement('passwordunmask', 'password2', get_string('setpasswordpassword2', 'auth_flexaccess'));
        $mform->setType('password2', PARAM_RAW);
        $mform->addRule('password2', get_string('required'), 'required', null, 'client');

        $this->add_action_buttons(false, get_string('setpasswordsubmit', 'auth_flexaccess'));
    }

    /**
     * Validate the two passwords match and satisfy the site password policy.
     *
     * @param array $data Submitted data.
     * @param array $files Files.
     * @return array
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        if (($data['password'] ?? '') !== ($data['password2'] ?? '')) {
            $errors['password2'] = get_string('setpasswordmismatch', 'auth_flexaccess');
            return $errors;
        }
        $errmsg = '';
        if (!check_password_policy((string) ($data['password'] ?? ''), $errmsg)) {
            $errors['password'] = $errmsg;
        }
        return $errors;
    }
}
