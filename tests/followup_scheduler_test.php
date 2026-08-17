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
 * Tests for the FlexAccess follow-up scheduler.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace auth_flexaccess;

use auth_flexaccess\local\account_state;
use auth_flexaccess\local\account_type;
use auth_flexaccess\local\followup_scheduler;

/** Follow-up scheduler tests. */
final class followup_scheduler_test extends \advanced_testcase {
    /** With no expiry, the planned time stands. */
    public function test_no_expiry_uses_planned(): void {
        $this->assertSame(1000 + 86400,
            followup_scheduler::due_time(1000, 86400, null));
        $this->assertSame(1000 + 86400,
            followup_scheduler::due_time(1000, 86400, 0));
    }

    /** When the planned time fits before expiry minus margin, it is used. */
    public function test_planned_within_expiry(): void {
        // Created 0, after 1h, expiry in 24h, margin 1h -> planned 3600 < latest 82800.
        $this->assertSame(3600,
            followup_scheduler::due_time(0, 3600, 86400, 3600));
    }

    /** When the planned time is after the latest safe time, it is clamped. */
    public function test_clamped_before_expiry(): void {
        // Created 0, after 24h, expiry in 24h, margin 1h -> latest 82800 < planned 86400.
        $this->assertSame(82800,
            followup_scheduler::due_time(0, 86400, 86400, 3600));
    }

    /** A too-short account cannot fit a follow-up. */
    public function test_too_short_returns_null(): void {
        // Expiry in 30 min, margin 1h -> latest negative -> null.
        $this->assertNull(followup_scheduler::due_time(0, 86400, 1800, 3600));
    }

    /** Only unconverted temporary users in ephemeral/provisional state qualify. */
    public function test_should_schedule_matrix(): void {
        $this->assertTrue(followup_scheduler::should_schedule(
            account_type::TEMPORARY_USER, account_state::EPHEMERAL));
        $this->assertTrue(followup_scheduler::should_schedule(
            account_type::TEMPORARY_USER, account_state::PROVISIONAL));
        $this->assertFalse(followup_scheduler::should_schedule(
            account_type::TEMPORARY_USER, account_state::ACTIVE));
        $this->assertFalse(followup_scheduler::should_schedule(
            account_type::AUTHENTICATED_USER, account_state::ACTIVE));
        $this->assertFalse(followup_scheduler::should_schedule(
            account_type::TEMPORARY_USER, account_state::SUSPENDED));
    }
}
