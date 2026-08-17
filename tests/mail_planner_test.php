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

/**
 * Tests for the FlexAccess mail planner.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace auth_flexaccess;

use auth_flexaccess\local\mail_planner;

/** Mail planner tests. */
final class mail_planner_test extends \advanced_testcase {
    /** Unlimited capacity sends all due mails. */
    public function test_unlimited(): void {
        $this->assertSame(5, mail_planner::sendable(null, 5));
        $this->assertSame(0, mail_planner::sendable(null, 0));
    }

    /** Limited capacity caps the number sent. */
    public function test_limited(): void {
        $this->assertSame(3, mail_planner::sendable(3, 5));
        $this->assertSame(5, mail_planner::sendable(10, 5));
        $this->assertSame(0, mail_planner::sendable(0, 5));
        $this->assertSame(0, mail_planner::sendable(3, 0));
    }
}
