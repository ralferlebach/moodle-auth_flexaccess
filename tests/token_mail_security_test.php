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
use auth_flexaccess\local\mail_worker;

/**
 * Security tests for the token mail flow: no plaintext secret is ever persisted in the queue.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\auth_flexaccess\local\mail_worker::class)]
#[CoversClass(\auth_flexaccess\local\mail_renderer::class)]
final class token_mail_security_test extends \advanced_testcase {
    /**
     * The queued magic-login job carries no token; the token appears only in the delivered mail.
     *
     * @return void
     */
    public function test_token_absent_from_queue_present_in_delivered_mail(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user([
            'email' => 'magic@example.com', 'username' => 'magic@example.com', 'auth' => 'flexaccess',
        ]);
        account_service::create_authenticated((int) $user->id, '2222222222');

        \auth_flexaccess\api::request_magic_login('magic@example.com', '203.0.113.7');

        // The persisted queue payload must not contain a token parameter or any 64-hex secret.
        $rows = $DB->get_records('auth_flexaccess_mailqueue', ['status' => 'queued']);
        $this->assertCount(1, $rows);
        $payload = reset($rows)->payloadjson;
        $this->assertStringNotContainsString('token=', $payload);
        $this->assertDoesNotMatchRegularExpression('/[a-f0-9]{40,}/i', $payload);
        // No token has been issued yet, only queued.
        $this->assertEquals(0, $DB->count_records('auth_flexaccess_token', ['userid' => $user->id]));

        // Deliver: the worker issues the token, stores only its hash, and the mail carries the link.
        $sink = $this->redirectEmails();
        mail_worker::run(time());
        $messages = $sink->get_messages();
        $sink->close();

        $this->assertCount(1, $messages);
        $body = quoted_printable_decode($messages[0]->body);
        $this->assertMatchesRegularExpression('/token=[A-Za-z0-9]+/', $body);
        // Exactly one hashed token row now exists; the raw token is not stored anywhere.
        $this->assertEquals(1, $DB->count_records('auth_flexaccess_token', ['userid' => $user->id]));
        preg_match('/token=([A-Za-z0-9]+)/', $body, $m);
        $this->assertFalse($DB->record_exists('auth_flexaccess_token', ['tokenhash' => $m[1]]));
    }

    /**
     * A delivery retry revokes the previous token, leaving exactly one live secret.
     *
     * @return void
     */
    public function test_retry_leaves_single_live_token(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user([
            'email' => 'retry@example.com', 'username' => 'retry@example.com', 'auth' => 'flexaccess',
        ]);
        account_service::create_authenticated((int) $user->id, '3333333333');

        \auth_flexaccess\api::request_magic_login('retry@example.com', '203.0.113.8');

        $sink = $this->redirectEmails();
        mail_worker::run(time());
        // Simulate a second delivery of the same job by re-queuing and running again.
        $DB->set_field('auth_flexaccess_mailqueue', 'status', 'queued', ['userid' => $user->id]);
        $DB->set_field('auth_flexaccess_mailqueue', 'nextrun', time(), ['userid' => $user->id]);
        mail_worker::run(time());
        $sink->close();

        // Only one unused token remains after the retry (the earlier one was revoked).
        $this->assertEquals(
            1,
            $DB->count_records_select(
                'auth_flexaccess_token',
                'userid = :uid AND timeused IS NULL',
                ['uid' => $user->id]
            )
        );
    }
}
