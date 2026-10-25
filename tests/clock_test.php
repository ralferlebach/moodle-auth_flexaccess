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
 * Account, token, mail, merge and rate-limit times read Moodle's clock (issue #15).
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace auth_flexaccess;

use auth_flexaccess\local\account_service;
use auth_flexaccess\local\login_guard;
use auth_flexaccess\local\mail_worker;
use auth_flexaccess\local\merge_registry;
use auth_flexaccess\local\rate_limiter;
use auth_flexaccess\local\token_service;

/**
 * Boundaries at T-1, T and T+1 with a frozen clock; one reference time per operation with an
 * incrementing clock; an explicit $now still takes precedence.
 *
 * @package    auth_flexaccess
 * @covers \auth_flexaccess\local\login_guard
 * @covers \auth_flexaccess\local\token_service
 * @covers \auth_flexaccess\local\account_service
 * @covers \auth_flexaccess\local\mail_worker
 * @covers \auth_flexaccess\local\merge_registry
 * @covers \auth_flexaccess\local\rate_limiter
 * @covers \auth_flexaccess\api
 */
final class clock_test extends \advanced_testcase {
    /** Reference time T (2033-05-18). */
    private const T = 2000000000;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('auth', 'flexaccess');
        \core\plugin_manager::reset_caches();
    }

    /**
     * Expiry boundaries of a temporary account: valid before T, expired from T on.
     *
     * @return array
     */
    public static function expiry_provider(): array {
        return [
            'T-1' => [-1, login_guard::OK, 0],
            'T' => [0, login_guard::REASON_EXPIRED, 1],
            'T+1' => [1, login_guard::REASON_EXPIRED, 1],
        ];
    }

    /**
     * The login guard and the expiry run decide by the clock.
     *
     * @dataProvider expiry_provider
     * @param int $offset Clock offset from T.
     * @param string $reason Expected guard result.
     * @param int $expired Expected number of accounts expired by expire_due().
     */
    public function test_account_expiry_follows_clock(int $offset, string $reason, int $expired): void {
        $userid = api::create_temporary_user(self::T, null, null, self::T - DAYSECS);
        $this->mock_clock_with_frozen(self::T + $offset);
        $this->assertSame($reason, login_guard::evaluate($userid, login_guard::CHANNEL_ENTRY));
        $this->assertSame($expired, account_service::expire_due());
    }

    /**
     * An explicit $now overrides the clock.
     */
    public function test_explicit_now_wins(): void {
        $userid = api::create_temporary_user(self::T, null, null, self::T - DAYSECS);
        $this->mock_clock_with_frozen(self::T + 10);
        $this->assertSame(login_guard::REASON_EXPIRED, login_guard::evaluate($userid, login_guard::CHANNEL_ENTRY));
        $this->assertSame(login_guard::OK, login_guard::evaluate($userid, login_guard::CHANNEL_ENTRY, self::T - 10));
    }

    /**
     * Tokens: valid until (excluding) their expiry, consumed with the clock's time.
     */
    public function test_token_expiry_follows_clock(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $clock = $this->mock_clock_with_frozen(self::T);
        $secret = token_service::issue((int) $user->id, 'magic', 100);
        $row = $DB->get_record('auth_flexaccess_token', ['userid' => $user->id], '*', MUST_EXIST);
        $this->assertSame(self::T, (int) $row->timecreated);
        $this->assertSame(self::T + 100, (int) $row->timeexpires);

        $clock->set_to(self::T + 99);
        $this->assertNotNull(token_service::verify($secret, 'magic'));
        $clock->set_to(self::T + 100);
        $this->assertNull(token_service::verify($secret, 'magic'));
        $clock->set_to(self::T + 99);
        $this->assertSame((int) $user->id, token_service::consume($secret, 'magic'));
        $this->assertSame(self::T + 99, (int) $DB->get_field('auth_flexaccess_token', 'timeused', ['id' => $row->id]));
    }

    /**
     * One operation, one time: created and expiry differ by exactly the lifetime even when every clock
     * read returns a later second.
     */
    public function test_operations_use_one_reference_time(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $this->mock_clock_with_incrementing(self::T);
        token_service::issue((int) $user->id, 'magic', 600);
        $row = $DB->get_record('auth_flexaccess_token', ['userid' => $user->id], '*', MUST_EXIST);
        $this->assertSame((int) $row->timecreated + 600, (int) $row->timeexpires);

        $jobid = api::queue_mail((int) $user->id, $user->email, 'Subject', 'Body');
        $job = $DB->get_record('auth_flexaccess_mailqueue', ['id' => $jobid], '*', MUST_EXIST);
        $this->assertSame((int) $job->timecreated, (int) $job->nextrun);
        $this->assertGreaterThan((int) $row->timecreated, (int) $job->timecreated, 'The clock advanced in between.');
    }

    /**
     * Queued mail is sent once its run time is reached by the clock, not before.
     */
    public function test_mail_queue_follows_clock(): void {
        global $DB;
        $sink = $this->redirectEmails();
        $user = $this->getDataGenerator()->create_user();
        $jobid = api::queue_mail((int) $user->id, $user->email, 'Subject', 'Body', '', 'welcome', self::T, self::T - 60);
        $clock = $this->mock_clock_with_frozen(self::T - 1);
        $this->assertSame(0, mail_worker::run());
        $clock->set_to(self::T);
        $this->assertSame(1, mail_worker::run());
        $this->assertSame(self::T, (int) $DB->get_field('auth_flexaccess_mailqueue', 'timesent', ['id' => $jobid]));
        $this->assertCount(1, $sink->get_messages());
        $sink->close();
    }

    /**
     * A merge approval is valid for its lifetime from the approval time, measured by the clock.
     */
    public function test_merge_approval_lifetime_follows_clock(): void {
        $from = (int) $this->getDataGenerator()->create_user()->id;
        $to = (int) $this->getDataGenerator()->create_user()->id;
        $admin = (int) get_admin()->id;
        $clock = $this->mock_clock_with_frozen(self::T);
        $evidence = 'Student record 4711 checked by the office.';
        $row = merge_registry::approve($from, $to, $admin, merge_registry::PROOF_DOCUMENTS, $evidence);
        $this->assertSame(self::T, (int) $row->timeapproved);

        $clock->set_to(self::T + merge_registry::APPROVAL_LIFETIME - 1);
        $this->assertTrue(merge_registry::is_approved($from, $to));
        $clock->set_to(self::T + merge_registry::APPROVAL_LIFETIME);
        $this->assertFalse(merge_registry::is_approved($from, $to));
        $clock->set_to(self::T + merge_registry::APPROVAL_LIFETIME + 1);
        $this->assertFalse(merge_registry::is_approved($from, $to));
    }

    /**
     * The sliding rate-limit window is measured by the clock.
     */
    public function test_rate_limit_window_follows_clock(): void {
        $clock = $this->mock_clock_with_frozen(self::T);
        $this->assertFalse(rate_limiter::hit('clocktest', 'id', 2, 60));
        $this->assertFalse(rate_limiter::hit('clocktest', 'id', 2, 60));
        $clock->set_to(self::T + 59);
        $this->assertTrue(rate_limiter::too_many('clocktest', 'id', 2, 60));
        $this->assertTrue(rate_limiter::hit('clocktest', 'id', 2, 60), 'Third action within the window.');
        // At T+60 the two hits of T have left the window; the one of T+59 is still in it.
        $clock->set_to(self::T + 60);
        $this->assertFalse(rate_limiter::too_many('clocktest', 'id', 2, 60));
        $clock->set_to(self::T + 61);
        $this->assertFalse(rate_limiter::hit('clocktest', 'id', 2, 60));
    }
}
