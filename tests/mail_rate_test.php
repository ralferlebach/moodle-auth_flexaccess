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
 * Tests for FlexAccess mail-rate calculations.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace auth_flexaccess;

/**
 * Mail-rate helper tests.
 *
 * @package    auth_flexaccess
 * @covers     \auth_flexaccess\local\mail_rate
 */
final class mail_rate_test extends \advanced_testcase {
    /**
     * Test rolling-hour remaining capacity.
     */
    public function test_remaining_capacity(): void {
        $this->assertSame(37, \auth_flexaccess\local\mail_rate::remaining(50, 13));
        $this->assertSame(0, \auth_flexaccess\local\mail_rate::remaining(10, 12));
        $this->assertNull(\auth_flexaccess\local\mail_rate::remaining(0, 999));
    }
}
