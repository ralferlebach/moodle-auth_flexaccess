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

namespace auth_flexaccess;

use auth_flexaccess\local\target_resolver;

/**
 * A wantsurl must never point at a different course than the one whose policy is evaluated.
 *
 * Otherwise access could be granted under course A's policy and capacity and the visitor then
 * redirected into course B. The entry point drops a contradicting target; these tests pin down the
 * resolver behaviour that decision relies on.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\auth_flexaccess\local\target_resolver::class)]
final class target_consistency_test extends \advanced_testcase {
    public function test_resolver_reports_the_course_of_a_course_url(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        $target = target_resolver::resolve('/course/view.php?id=' . $course->id);

        $this->assertNotNull($target);
        $this->assertSame((int) $course->id, (int) $target->courseid);
    }

    public function test_mismatch_between_courseid_and_wantsurl_is_detectable(): void {
        $this->resetAfterTest();
        $a = $this->getDataGenerator()->create_course();
        $b = $this->getDataGenerator()->create_course();

        $target = target_resolver::resolve('/course/view.php?id=' . $b->id);

        // The entry point compares exactly these two values and discards the target on mismatch.
        $this->assertNotNull($target);
        $this->assertNotSame((int) $a->id, (int) $target->courseid);
    }

    public function test_activity_url_resolves_to_its_own_course(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('flexaccess', ['course' => $course->id]);

        $target = target_resolver::resolve('/mod/flexaccess/view.php?id=' . $module->cmid);

        $this->assertNotNull($target);
        $this->assertSame((int) $course->id, (int) $target->courseid);
    }
}
