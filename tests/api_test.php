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
 * Tests for the auth_flexaccess public facade.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace auth_flexaccess;

use auth_flexaccess\local\account_state;
use auth_flexaccess\local\account_type;
use auth_flexaccess\local\mail_kind;

/**
 * Facade tests.
 */
final class api_test extends \advanced_testcase {
    /**
     * Insert a FlexAccess account row for a user.
     *
     * @param int $userid User id.
     * @param string $type Account type.
     * @param string $state Account state.
     * @param int $created Creation time.
     * @param int|null $expires Expiry time.
     * @return void
     */
    private function make_account(int $userid, string $type, string $state, int $created, ?int $expires): void {
        global $DB;
        $DB->insert_record('auth_flexaccess_account', (object) [
            'userid' => $userid,
            'accounttype' => $type,
            'accountstate' => $state,
            'referencecode' => 'REF' . $userid,
            'timecreated' => $created,
            'timeexpires' => $expires,
            'timemodified' => $created,
        ]);
    }

    /**
     * A user without a FlexAccess record is an authenticated user.
     */
    public function test_classify_without_record(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->assertSame(account_type::AUTHENTICATED_USER, \auth_flexaccess\api::classify_user($user->id));
    }

    /**
     * A temporary FlexAccess user classifies as temporary.
     */
    public function test_classify_temporary(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->make_account($user->id, account_type::TEMPORARY_USER, account_state::EPHEMERAL, time(), null);
        $this->assertSame(account_type::TEMPORARY_USER, \auth_flexaccess\api::classify_user($user->id));
    }

    /**
     * A qualifying temporary user with an e-mail gets exactly one queued follow-up.
     */
    public function test_request_followup_enqueues_once(): void {
        global $DB;
        $this->resetAfterTest();
        $now = time();
        $user = $this->getDataGenerator()->create_user(['email' => 'temp@example.com']);
        $this->make_account(
            $user->id,
            account_type::TEMPORARY_USER,
            account_state::PROVISIONAL,
            $now,
            $now + DAYSECS
        );

        $this->assertTrue(\auth_flexaccess\api::request_persistence_followup($user->id, DAYSECS, 3600));
        $rows = $DB->get_records(
            'auth_flexaccess_mailqueue',
            ['userid' => $user->id, 'mailtype' => mail_kind::PERSISTENCE_FOLLOWUP]
        );
        $this->assertCount(1, $rows);
        $row = reset($rows);
        $this->assertSame('queued', $row->status);
        // Clamped to expiry - margin.
        $this->assertSame($now + DAYSECS - 3600, (int) $row->nextrun);

        // Idempotent second call does not enqueue again.
        $this->assertFalse(\auth_flexaccess\api::request_persistence_followup($user->id, DAYSECS, 3600));
        $this->assertEquals(1, $DB->count_records(
            'auth_flexaccess_mailqueue',
            ['userid' => $user->id, 'mailtype' => mail_kind::PERSISTENCE_FOLLOWUP]
        ));
    }

    /**
     * A converted (authenticated) user is not scheduled.
     */
    public function test_request_followup_skips_authenticated(): void {
        $this->resetAfterTest();
        $now = time();
        $user = $this->getDataGenerator()->create_user(['email' => 'real@example.com']);
        $this->make_account($user->id, account_type::AUTHENTICATED_USER, account_state::ACTIVE, $now, null);
        $this->assertFalse(\auth_flexaccess\api::request_persistence_followup($user->id, DAYSECS));
    }

    /**
     * An account too short-lived to fit a reminder is not scheduled.
     */
    public function test_request_followup_skips_too_short(): void {
        $this->resetAfterTest();
        $now = time();
        $user = $this->getDataGenerator()->create_user(['email' => 'temp2@example.com']);
        $this->make_account(
            $user->id,
            account_type::TEMPORARY_USER,
            account_state::EPHEMERAL,
            $now,
            $now + 1800
        );
        $this->assertFalse(\auth_flexaccess\api::request_persistence_followup($user->id, DAYSECS, 3600));
    }

    /**
     * A temporary user self-activates with a fresh e-mail.
     */
    public function test_self_activate_success(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['email' => 'temp-sa@example.com']);
        $this->make_account($user->id, account_type::TEMPORARY_USER, account_state::EPHEMERAL, time(), null);

        $status = \auth_flexaccess\api::self_activate($user->id, 'Keep.Me@example.com', 'Kim', 'Keep');
        $this->assertSame('activated', $status);
        $this->assertSame(account_type::AUTHENTICATED_USER, \auth_flexaccess\api::classify_user($user->id));
        $fresh = $DB->get_record('user', ['id' => $user->id]);
        $this->assertSame('keep.me@example.com', $fresh->email);
        $this->assertSame('Kim', $fresh->firstname);
    }

    /**
     * A duplicate e-mail is rejected without converting.
     */
    public function test_self_activate_email_taken(): void {
        $this->resetAfterTest();
        $this->getDataGenerator()->create_user(['email' => 'taken@example.com']);
        $user = $this->getDataGenerator()->create_user(['email' => 'temp-b@example.com']);
        $this->make_account($user->id, account_type::TEMPORARY_USER, account_state::EPHEMERAL, time(), null);

        $this->assertSame('emailtaken', \auth_flexaccess\api::self_activate($user->id, 'taken@example.com'));
        $this->assertSame(account_type::TEMPORARY_USER, \auth_flexaccess\api::classify_user($user->id));
    }

    /**
     * An invalid e-mail is rejected.
     */
    public function test_self_activate_invalid_email(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->make_account($user->id, account_type::TEMPORARY_USER, account_state::EPHEMERAL, time(), null);
        $this->assertSame('invalidemail', \auth_flexaccess\api::self_activate($user->id, 'not-an-email'));
    }

    /**
     * An authenticated user is not applicable for self-activation.
     */
    public function test_self_activate_not_applicable(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['email' => 'auth@example.com']);
        $this->assertSame('notapplicable', \auth_flexaccess\api::self_activate($user->id, 'new@example.com'));
    }

    /**
     * Search filters by substring, type and state; admin_convert converts.
     */
    public function test_search_count_and_admin_convert(): void {
        $this->resetAfterTest();
        $now = time();
        $alice = $this->getDataGenerator()->create_user(['email' => 'alice@example.com', 'firstname' => 'Alice']);
        $bob = $this->getDataGenerator()->create_user(['email' => 'bob@example.com', 'firstname' => 'Bob']);
        $carol = $this->getDataGenerator()->create_user(['email' => 'carol@example.com', 'firstname' => 'Carol']);
        $this->make_account($alice->id, account_type::TEMPORARY_USER, account_state::EPHEMERAL, $now, null);
        $this->make_account($bob->id, account_type::TEMPORARY_USER, account_state::PROVISIONAL, $now, null);
        $this->make_account($carol->id, account_type::AUTHENTICATED_USER, account_state::ACTIVE, $now, null);

        $this->assertSame(3, \auth_flexaccess\api::count_accounts());
        $this->assertSame(2, \auth_flexaccess\api::count_accounts('', account_type::TEMPORARY_USER));
        $this->assertSame(1, \auth_flexaccess\api::count_accounts('', null, account_state::PROVISIONAL));
        $this->assertSame(1, \auth_flexaccess\api::count_accounts('alice'));

        $found = \auth_flexaccess\api::search_accounts('bob');
        $this->assertCount(1, $found);
        $this->assertSame('bob@example.com', reset($found)->email);

        // Admin conversion flips a temporary account.
        $this->assertTrue(\auth_flexaccess\api::admin_convert($alice->id));
        $this->assertSame(account_type::AUTHENTICATED_USER, \auth_flexaccess\api::classify_user($alice->id));
        $this->assertFalse(\auth_flexaccess\api::admin_convert($carol->id));
    }

    /**
     * account_stats and mailqueue_summary aggregate correctly.
     */
    public function test_stats_and_mailqueue_summary(): void {
        global $DB;
        $this->resetAfterTest();
        $now = 1000000;
        $a = $this->getDataGenerator()->create_user();
        $b = $this->getDataGenerator()->create_user();
        $this->make_account($a->id, account_type::TEMPORARY_USER, account_state::PROVISIONAL, $now, null);
        $this->make_account($b->id, account_type::AUTHENTICATED_USER, account_state::ACTIVE, $now, null);

        $stats = \auth_flexaccess\api::account_stats();
        $this->assertSame(2, $stats['total']);
        $this->assertSame(1, $stats['temporary']);
        $this->assertSame(1, $stats['authenticated']);
        $this->assertSame(1, $stats['provisional']);

        $DB->insert_record('auth_flexaccess_mailqueue', (object) [
            'userid' => $a->id, 'recipient' => 'a@example.com', 'mailtype' => 'persistence_followup',
            'payloadjson' => null, 'status' => 'queued', 'attempts' => 0,
            'timecreated' => $now, 'nextrun' => $now + 500, 'timesent' => null,
        ]);
        $DB->insert_record('auth_flexaccess_mailqueue', (object) [
            'userid' => $b->id, 'recipient' => 'b@example.com', 'mailtype' => 'persistence_followup',
            'payloadjson' => null, 'status' => 'sent', 'attempts' => 1,
            'timecreated' => $now, 'nextrun' => $now, 'timesent' => $now,
        ]);

        $summary = \auth_flexaccess\api::mailqueue_summary();
        $this->assertSame(1, $summary['queued']);
        $this->assertSame(1, $summary['sent']);
        $this->assertSame($now + 500, $summary['nextdue']);
        $this->assertSame(2, \auth_flexaccess\api::count_mailqueue());
        $this->assertSame(1, \auth_flexaccess\api::count_mailqueue('queued'));
        $this->assertCount(1, \auth_flexaccess\api::list_mailqueue('sent'));
    }

    /**
     * create_temporary_user creates a FlexAccess user with temporary account metadata.
     */
    public function test_create_temporary_user(): void {
        global $DB, $CFG;
        $this->resetAfterTest();
        $now = 1000000;
        $userid = \auth_flexaccess\api::create_temporary_user($now + DAYSECS, 42, null, $now);

        $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
        $this->assertSame('flexaccess', $user->auth);
        $this->assertSame(1, (int) $user->confirmed);
        $this->assertSame(1, (int) $user->emailstop);
        $this->assertSame(account_type::TEMPORARY_USER, \auth_flexaccess\api::classify_user($userid));

        $account = $DB->get_record('auth_flexaccess_account', ['userid' => $userid], '*', MUST_EXIST);
        $this->assertSame($now + DAYSECS, (int) $account->timeexpires);
        $this->assertSame(42, (int) $account->sourcecourseid);
    }
}
