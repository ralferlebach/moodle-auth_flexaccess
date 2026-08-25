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

use PHPUnit\Framework\Attributes\CoversClass;
use auth_flexaccess\local\target_resolver;

/**
 * Tests for the wantsurl target resolver.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\auth_flexaccess\local\target_resolver::class)]
final class target_resolver_test extends \advanced_testcase {
    /**
     * A course view URL resolves to that course with no activity.
     *
     * @return void
     */
    public function test_course_deep_link(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        $target = target_resolver::resolve('/course/view.php?id=' . $course->id);

        $this->assertNotNull($target);
        $this->assertSame((int) $course->id, $target->courseid);
        $this->assertSame(0, $target->cmid);
    }

    /**
     * An activity view URL resolves to the owning course and the activity.
     *
     * @return void
     */
    public function test_activity_deep_link(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        $target = target_resolver::resolve('/mod/page/view.php?id=' . $page->cmid);

        $this->assertNotNull($target);
        $this->assertSame((int) $course->id, $target->courseid);
        $this->assertSame((int) $page->cmid, $target->cmid);
    }

    /**
     * External URLs are rejected (no open redirect).
     *
     * @return void
     */
    public function test_external_url_rejected(): void {
        $this->resetAfterTest();
        $this->assertNull(target_resolver::resolve('https://evil.example.com/course/view.php?id=1'));
    }

    /**
     * Empty and unrelated local URLs resolve to null.
     *
     * @return void
     */
    public function test_empty_and_unrelated(): void {
        $this->resetAfterTest();
        $this->assertNull(target_resolver::resolve(''));
        $this->assertNull(target_resolver::resolve(null));
        $this->assertNull(target_resolver::resolve('/my/'));
        $this->assertNull(target_resolver::resolve('/course/view.php'));
    }

    /**
     * A non-existent course id resolves to null rather than leaking a target.
     *
     * @return void
     */
    public function test_missing_course(): void {
        $this->resetAfterTest();
        $this->assertNull(target_resolver::resolve('/course/view.php?id=987654'));
    }
}
