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

namespace auth_flexaccess;

/**
 * The two password fields of a gated quick registration can no longer be confused (issue auth#9).
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \auth_flexaccess\form\access_gate_form
 * @covers \auth_flexaccess\form\quick_registration_form
 */
final class access_gate_form_test extends \advanced_testcase {
    /**
     * Elements of a form, by type.
     *
     * @param \moodleform $form Form.
     * @return array<string, string[]> type => element names.
     */
    private function elements(\moodleform $form): array {
        $mform = (new \ReflectionProperty(\moodleform::class, '_form'))->getValue($form);
        $out = [];
        foreach ($mform->_elements as $element) {
            $out[$element->getType()][] = (string) $element->getName();
        }
        return $out;
    }

    /**
     * Gate step: exactly one password field, the course access password, and no personal data.
     *
     * @return void
     */
    public function test_gate_step_has_only_the_course_password(): void {
        $this->resetAfterTest();
        $form = new form\access_gate_form(null, ['courseid' => 2, 'wantsurl' => '']);
        $elements = $this->elements($form);
        $this->assertSame(['accesspassword'], $elements['passwordunmask'] ?? []);
        $this->assertArrayNotHasKey('password', $elements);
        foreach (['email', 'firstname', 'lastname'] as $personal) {
            $this->assertNotContains($personal, array_merge(...array_values($elements)));
        }
    }

    /**
     * Registration step: only the account's own password, no course access password field.
     *
     * @return void
     */
    public function test_registration_step_has_no_course_password(): void {
        $this->resetAfterTest();
        $form = new form\quick_registration_form(null, ['courseid' => 2, 'wantsurl' => '']);
        $all = array_merge(...array_values($this->elements($form)));
        $this->assertNotContains('accesspassword', $all);
        $this->assertContains('password', $all);
    }

    /**
     * No password travels in a hidden field of either step.
     *
     * @return void
     */
    public function test_no_password_in_hidden_fields(): void {
        $this->resetAfterTest();
        foreach (
            [
            new form\access_gate_form(null, ['courseid' => 2, 'wantsurl' => '']),
            new form\quick_registration_form(null, ['courseid' => 2, 'wantsurl' => '']),
            ] as $form
        ) {
            foreach ($this->elements($form)['hidden'] ?? [] as $hidden) {
                $this->assertStringNotContainsStringIgnoringCase('password', $hidden);
            }
        }
    }

    /**
     * The labels tell the course access password and the account password apart.
     *
     * @return void
     */
    public function test_labels_are_distinct(): void {
        $course = get_string('registeraccesspassword', 'auth_flexaccess');
        $this->assertNotSame(get_string('password'), $course);
        $this->assertStringContainsStringIgnoringCase('course', $course);
        $this->assertTrue(get_string_manager()->string_exists('registeraccesspassword_help', 'auth_flexaccess'));
    }
}
