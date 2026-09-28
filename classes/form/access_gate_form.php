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
 * First step of a password-protected quick registration: the course access password, alone.
 *
 * Deliberately a form of its own with exactly one password field, so the course access password can
 * never be mistaken for a "repeat password" field of the account that is only created afterwards.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class access_gate_form extends \moodleform {
    /**
     * Form definition.
     *
     * @return void
     */
    protected function definition(): void {
        $mform = $this->_form;
        $mform->addElement('static', 'gateintro', '', get_string('registergateintro', 'auth_flexaccess'));
        $mform->addElement('passwordunmask', 'accesspassword', get_string('registeraccesspassword', 'auth_flexaccess'));
        $mform->setType('accesspassword', PARAM_RAW);
        $mform->addRule('accesspassword', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('accesspassword', 'registeraccesspassword', 'auth_flexaccess');

        $mform->addElement('hidden', 'courseid');
        $mform->setType('courseid', PARAM_INT);
        $mform->setDefault('courseid', (int) ($this->_customdata['courseid'] ?? 0));
        $mform->addElement('hidden', 'wantsurl');
        $mform->setType('wantsurl', PARAM_LOCALURL);
        $mform->setDefault('wantsurl', (string) ($this->_customdata['wantsurl'] ?? ''));
        $mform->addElement('hidden', 'gatestep', 1);
        $mform->setType('gatestep', PARAM_INT);

        $this->add_action_buttons(true, get_string('registergatesubmit', 'auth_flexaccess'));
    }
}
