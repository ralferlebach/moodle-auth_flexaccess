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
use auth_flexaccess\local\account_service;
use auth_flexaccess\local\account_type;
use auth_flexaccess\local\account_state;
use auth_flexaccess\local\mail_worker;

/**
 * Tests for passwordless magic-login.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\auth_flexaccess\api::class)]
final class magic_login_test extends \advanced_testcase {
    /**
     * Create a permanent, active FlexAccess account for a fresh user.
     *
     * @param string $email Email address.
     * @return int User id.
     */
    private function permanent_user(string $email): int {
        global $DB;
        $user = $this->getDataGenerator()->create_user(['email' => $email, 'username' => $email, 'auth' => 'flexaccess']);
        account_service::create_authenticated((int) $user->id, '1234567890');
        return (int) $user->id;
    }

    /**
     * Requesting a link queues a mail; the worker sends it; the token logs the account in.
     *
     * @return void
     */
    public function test_request_queues_and_token_logs_in(): void {
        global $DB;
        $this->resetAfterTest();
        $userid = $this->permanent_user('magic@example.com');

        $sink = $this->redirectEmails();
        $this->assertSame('sent', \auth_flexaccess\api::request_magic_login('magic@example.com'));
        // Mail is queued, not sent synchronously (goes through the rate-limited queue).
        $this->assertSame(0, $sink->count());
        $this->assertEquals(1, $DB->count_records('auth_flexaccess_mailqueue', ['status' => 'queued']));

        mail_worker::run(time());
        $messages = $sink->get_messages();
        $sink->close();
        $this->assertCount(1, $messages);
        $this->assertSame('magic@example.com', $messages[0]->to);

        $decoded = quoted_printable_decode($messages[0]->body);
        $this->assertMatchesRegularExpression('/token=[A-Za-z0-9]{64}/', $decoded);
        preg_match('/token=([A-Za-z0-9]+)/', $decoded, $m);

        $this->assertSame($userid, \auth_flexaccess\api::consume_magic_login($m[1]));
        // Single-use: the token cannot be replayed.
        $this->assertNull(\auth_flexaccess\api::consume_magic_login($m[1]));
    }

    /**
     * An unknown address reports success but queues nothing (no account enumeration).
     *
     * @return void
     */
    public function test_unknown_email_reports_sent_without_queueing(): void {
        global $DB;
        $this->resetAfterTest();
        $this->assertSame('sent', \auth_flexaccess\api::request_magic_login('nobody@example.com'));
        $this->assertEquals(0, $DB->count_records('auth_flexaccess_mailqueue', []));
    }

    /**
     * A suspended account cannot be logged in via a magic token even if one is issued.
     *
     * @return void
     */
    public function test_suspended_account_token_is_rejected(): void {
        global $DB;
        $this->resetAfterTest();
        $userid = $this->permanent_user('suspended@example.com');
        $token = local\token_service::issue($userid, 'magiclogin', 900);

        // Suspend the account after the token was issued.
        $DB->set_field('auth_flexaccess_account', 'accountstate', account_state::SUSPENDED, ['userid' => $userid]);
        $this->assertNull(\auth_flexaccess\api::consume_magic_login($token));
    }

    /**
     * A temporary account is never eligible for a magic link.
     *
     * @return void
     */
    public function test_temporary_account_gets_no_link(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user([
            'email' => 'temp@example.com', 'username' => 'temp@example.com', 'auth' => 'flexaccess',
        ]);
        account_service::create_temporary((int) $user->id, '9999999999', time() + DAYSECS);

        $this->assertSame('sent', \auth_flexaccess\api::request_magic_login('temp@example.com'));
        $this->assertEquals(0, $DB->count_records('auth_flexaccess_mailqueue', []));
    }

    /**
     * When the feature is disabled, requests report 'disabled' and queue nothing.
     *
     * @return void
     */
    public function test_disabled_feature(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('allowmagiclogin', 0, 'auth_flexaccess');
        $this->permanent_user('off@example.com');
        $this->assertSame('disabled', \auth_flexaccess\api::request_magic_login('off@example.com'));
        $this->assertEquals(0, $DB->count_records('auth_flexaccess_mailqueue', []));
    }
}
