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

use auth_flexaccess\local\rate_limiter;

/**
 * Tests for the generic action rate limiter and its use on the public write endpoints.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \auth_flexaccess\local\rate_limiter
 */
final class rate_limiter_test extends \advanced_testcase {
    /**
     * hit() records the action and reports over-limit atomically; identifiers are independent.
     *
     * @return void
     */
    public function test_hit_records_and_reports_over_limit(): void {
        $this->resetAfterTest();
        $now = 2000000;
        // Exactly $max actions are allowed; the next is over the limit.
        $this->assertFalse(rate_limiter::hit('h', 'a', 3, 60, $now));
        $this->assertFalse(rate_limiter::hit('h', 'a', 3, 60, $now));
        $this->assertFalse(rate_limiter::hit('h', 'a', 3, 60, $now));
        $this->assertTrue(rate_limiter::hit('h', 'a', 3, 60, $now));
        // A different identifier keeps its own count.
        $this->assertFalse(rate_limiter::hit('h', 'b', 3, 60, $now));
        // Pruning old rows frees the identifier again.
        rate_limiter::prune($now + 61);
        $this->assertFalse(rate_limiter::hit('h', 'a', 3, 60, $now + 61));
    }

    /**
     * The limiter blocks after the maximum and the window slides.
     *
     * @return void
     */
    public function test_sliding_window(): void {
        $this->resetAfterTest();
        $now = 1000000;
        for ($i = 0; $i < 3; $i++) {
            $this->assertFalse(rate_limiter::too_many('b', 'id', 3, 60, $now));
            rate_limiter::record('b', 'id', $now);
        }
        $this->assertTrue(rate_limiter::too_many('b', 'id', 3, 60, $now));
        // A different identifier is independent.
        $this->assertFalse(rate_limiter::too_many('b', 'other', 3, 60, $now));
        // Once the window has passed, the counter resets.
        $this->assertFalse(rate_limiter::too_many('b', 'id', 3, 60, $now + 61));
    }

    /**
     * Magic-login requests are throttled per target address (anti inbox-spam).
     *
     * @return void
     */
    public function test_magic_login_rate_limited_per_email(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user([
            'email' => 'target@example.com', 'username' => 'target@example.com', 'auth' => 'flexaccess',
        ]);
        \auth_flexaccess\local\account_service::create_authenticated((int) $user->id, '1111111111');

        // First few requests queue a mail; beyond the per-email limit nothing is queued.
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame('sent', \auth_flexaccess\api::request_magic_login('target@example.com', '203.0.113.9'));
        }
        $queuedafterlimit = $DB->count_records('auth_flexaccess_mailqueue', ['status' => 'queued']);
        $this->assertSame('sent', \auth_flexaccess\api::request_magic_login('target@example.com', '203.0.113.9'));
        $this->assertEquals(
            $queuedafterlimit,
            $DB->count_records('auth_flexaccess_mailqueue', ['status' => 'queued']),
            'No further mail should be queued once the per-email limit is reached.'
        );
    }
}
