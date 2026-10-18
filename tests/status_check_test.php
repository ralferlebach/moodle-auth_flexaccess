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
 * The lifecycle check stays registered in Site administration > Reports > System status.
 *
 * Regression guard: lib.php also carries the after_require_login callback; replacing the file's
 * content once dropped the status check callback without any test noticing.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \auth_flexaccess\check\lifecycle_invariants
 */
final class status_check_test extends \advanced_testcase {
    /**
     * Core's check manager lists the FlexAccess lifecycle check.
     */
    public function test_lifecycle_check_is_registered(): void {
        $this->resetAfterTest();
        $classes = array_map('get_class', \core\check\manager::get_checks('status'));
        $this->assertContains(check\lifecycle_invariants::class, $classes);
        $this->assertTrue(function_exists('auth_flexaccess_after_require_login'));
    }
}
