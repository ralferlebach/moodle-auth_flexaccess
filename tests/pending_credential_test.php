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

use auth_flexaccess\local\account_state;
use auth_flexaccess\local\login_guard;
use auth_flexaccess\local\mail_worker;

/**
 * Tests for the pending-credential state of administrative conversions (issue auth#6).
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \auth_flexaccess\api
 * @covers \auth_flexaccess\observer
 */
final class pending_credential_test extends \advanced_testcase {
    /**
     * Session creation reads the user agent, which the CLI test runner does not provide.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $_SERVER['HTTP_USER_AGENT'] = $_SERVER['HTTP_USER_AGENT'] ?? 'phpunit';
    }

    /**
     * Create a temporary user and convert it administratively.
     *
     * @param string $email Real address.
     * @return int User id.
     */
    private function converted(string $email): int {
        $userid = api::create_temporary_user(time() + 3600);
        $this->assertSame('converted', api::admin_convert($userid, $email, 'Real', 'Person'));
        return $userid;
    }

    /**
     * Deliver the queue and return the set-password token of the last mail to the address.
     *
     * @param \phpunit_phpmailer_sink $sink Mail sink.
     * @param string $email Address.
     * @return string|null Token.
     */
    private function deliver_token(\phpunit_phpmailer_sink $sink, string $email): ?string {
        mail_worker::run(time());
        $token = null;
        foreach ($sink->get_messages() as $message) {
            $body = quoted_printable_decode($message->body);
            if ($message->to === $email && preg_match('/setpassword\.php\?token=([A-Za-z0-9]+)/', $body, $m)) {
                $token = $m[1];
            }
        }
        $sink->clear();
        return $token;
    }

    /**
     * Cases 1, 2, 7: the conversion is pending, queued mail keeps it pending, login is blocked.
     *
     * @return void
     */
    public function test_conversion_is_pending_until_credential(): void {
        $this->resetAfterTest();
        $userid = $this->converted('pending.one@example.com');
        $account = api::get_account($userid);
        $this->assertSame(account_state::PENDING_CREDENTIAL, $account->accountstate);
        $this->assertSame('queued', api::credential_status($userid));
        $this->assertSame(login_guard::REASON_PENDINGCREDENTIAL, api::login_eligibility($userid, login_guard::CHANNEL_PASSWORD));
        $this->assertSame(login_guard::REASON_PENDINGCREDENTIAL, api::login_eligibility($userid, login_guard::CHANNEL_MAGIC));
        $this->assertFalse(api::complete_login($userid, login_guard::CHANNEL_PASSWORD));
        // Not deliverable-blocked: the Moodle user stays unsuspended so the mail can reach them.
        $this->assertEquals(0, \core_user::get_user($userid)->suspended);
    }

    /**
     * Case 3: a failed mail keeps the account pending and makes the failure visible.
     *
     * @return void
     */
    public function test_failed_mail_is_visible(): void {
        global $DB;
        $this->resetAfterTest();
        $userid = $this->converted('pending.fail@example.com');
        $DB->set_field('auth_flexaccess_mailqueue', 'status', 'failed', ['userid' => $userid, 'mailtype' => 'set_password']);
        $this->assertSame('failed', api::credential_status($userid));
        $this->assertSame(account_state::PENDING_CREDENTIAL, api::get_account($userid)->accountstate);
        $stats = api::mail_funnel_stats();
        $this->assertSame(1, $stats['failedfunnel']);
    }

    /**
     * Case 4: a successful token finalises atomically to ACTIVE (unsuspended, unrestricted) and logs in.
     *
     * @return void
     */
    public function test_token_finalises_to_active(): void {
        $this->resetAfterTest();
        $sink = $this->redirectEmails();
        $userid = $this->converted('pending.ok@example.com');
        $token = $this->deliver_token($sink, 'pending.ok@example.com');
        $this->assertNotNull($token);
        $this->assertSame('sent', api::credential_status($userid));
        $this->assertSame($userid, api::complete_set_password($token, 'Str0ng-Pass!23'));
        $this->assertSame(account_state::ACTIVE, api::get_account($userid)->accountstate);
        $this->assertSame('completed', api::credential_status($userid));
        $this->assertNull(get_user_preferences('auth_flexaccess_pendingcredential', null, $userid));
        $this->assertSame([], api::find_state_mismatches(null, 10, [$userid]));
        $this->assertTrue(\auth_flexaccess\local\login_guard::is_eligible($userid, login_guard::CHANNEL_PASSWORD));
        // The welcome mail with the username follows the completion, not the conversion.
        mail_worker::run(time());
        $this->assertGreaterThanOrEqual(1, $sink->count());
        $sink->close();
    }

    /**
     * Case 5: an expired token leaves the account pending, and a resend is possible.
     *
     * @return void
     */
    public function test_expired_token_allows_resend(): void {
        global $DB;
        $this->resetAfterTest();
        $sink = $this->redirectEmails();
        $userid = $this->converted('pending.late@example.com');
        $token = $this->deliver_token($sink, 'pending.late@example.com');
        $DB->set_field('auth_flexaccess_token', 'timeexpires', time() - 1, ['userid' => $userid, 'purpose' => 'setpassword']);
        $this->assertSame('expired', api::credential_status($userid));
        $this->assertNull(api::complete_set_password($token, 'Str0ng-Pass!23'));
        $this->assertSame(account_state::PENDING_CREDENTIAL, api::get_account($userid)->accountstate);
        $this->assertSame('queued', api::resend_set_password($userid));
        $sink->close();
    }

    /**
     * Case 6: after two resends only the newest link works; resends are rate limited.
     *
     * @return void
     */
    public function test_only_newest_link_works(): void {
        $this->resetAfterTest();
        $sink = $this->redirectEmails();
        $userid = $this->converted('pending.twice@example.com');
        $first = $this->deliver_token($sink, 'pending.twice@example.com');
        $this->assertSame('queued', api::resend_set_password($userid));
        $this->assertSame('alreadyqueued', api::resend_set_password($userid));
        $second = $this->deliver_token($sink, 'pending.twice@example.com');
        $this->assertSame('queued', api::resend_set_password($userid));
        $third = $this->deliver_token($sink, 'pending.twice@example.com');
        $this->assertNotNull($third);
        $this->assertNull(api::complete_set_password($first, 'Str0ng-Pass!23'));
        $this->assertNull(api::complete_set_password($second, 'Str0ng-Pass!23'));
        $this->assertSame($userid, api::complete_set_password($third, 'Str0ng-Pass!23'));
        // Rate limit per account.
        $other = $this->converted('pending.limit@example.com');
        $results = [];
        for ($i = 0; $i < 5; $i++) {
            mail_worker::run(time());
            $results[] = api::resend_set_password($other);
        }
        $this->assertContains('ratelimited', $results);
        $sink->close();
    }

    /**
     * A credential set through Moodle's own password flow finalises the pending account too.
     *
     * @return void
     */
    public function test_password_set_elsewhere_finalises(): void {
        $this->resetAfterTest();
        $userid = $this->converted('pending.core@example.com');
        update_internal_user_password(\core_user::get_user($userid), 'Str0ng-Pass!23');
        $this->assertSame(account_state::ACTIVE, api::get_account($userid)->accountstate);
        // Not applicable to accounts that are not pending.
        $this->assertSame('notapplicable', api::resend_set_password($userid));
    }
}
