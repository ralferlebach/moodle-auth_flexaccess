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

/**
 * Shared identity fields (email, name, password) used by the registration and persistence forms.
 *
 * Both flows collect the same minimal identity and validate it identically; only the surrounding
 * hidden fields, submit label and email-uniqueness exclusion differ, so those stay in each form.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait identity_fields {
    /**
     * Add the email, first name, last name and password elements to the form.
     *
     * @return void
     */
    protected function add_identity_fields(): void {
        $mform = $this->_form;

        $mform->addElement('text', 'email', get_string('email'));
        $mform->setType('email', PARAM_RAW_TRIMMED);
        $mform->addRule('email', get_string('required'), 'required', null, 'client');

        $mform->addElement('text', 'firstname', get_string('firstname'));
        $mform->setType('firstname', PARAM_TEXT);
        $mform->addRule('firstname', get_string('required'), 'required', null, 'client');

        $mform->addElement('text', 'lastname', get_string('lastname'));
        $mform->setType('lastname', PARAM_TEXT);
        $mform->addRule('lastname', get_string('required'), 'required', null, 'client');

        $mform->addElement('passwordunmask', 'password', get_string('password'));
        $mform->setType('password', PARAM_RAW);
        $mform->addRule('password', get_string('required'), 'required', null, 'client');
    }

    /**
     * Validate the identity fields: a valid, unused email and a password meeting the site policy.
     *
     * @param array $data Submitted form data.
     * @param int|null $excludeuserid User id to exclude from the email-uniqueness check.
     * @return array Field errors keyed by element name (empty when valid).
     */
    protected function validate_identity(array $data, ?int $excludeuserid = null): array {
        $errors = [];

        $email = \core_text::strtolower(trim((string) $data['email']));
        if (!validate_email($email)) {
            $errors['email'] = get_string('invalidemail');
        } else if (!\auth_flexaccess\api::email_available($email, $excludeuserid)) {
            $errors['email'] = get_string('register:emailtaken', 'auth_flexaccess');
        }

        $policyerror = '';
        if (!check_password_policy((string) $data['password'], $policyerror)) {
            $errors['password'] = $policyerror;
        }

        return $errors;
    }
}
