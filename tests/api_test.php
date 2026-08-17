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

/** Facade tests. */
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

    /** A user without a FlexAccess record is an authenticated user. */
    public function test_classify_without_record(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->assertSame(account_type::AUTHENTICATED_USER, \auth_flexaccess\api::classify_user($user->id));
    }

    /** A temporary FlexAccess user classifies as temporary. */
    public function test_classify_temporary(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->make_account($user->id, account_type::TEMPORARY_USER, account_state::EPHEMERAL, time(), null);
        $this->assertSame(account_type::TEMPORARY_USER, \auth_flexaccess\api::classify_user($user->id));
    }

    /** A qualifying temporary user with an e-mail gets exactly one queued follow-up. */
    public function test_request_followup_enqueues_once(): void {
        global $DB;
        $this->resetAfterTest();
        $now = time();
        $user = $this->getDataGenerator()->create_user(['email' => 'temp@example.com']);
        $this->make_account($user->id, account_type::TEMPORARY_USER, account_state::PROVISIONAL,
            $now, $now + DAYSECS);

        $this->assertTrue(\auth_flexaccess\api::request_persistence_followup($user->id, DAYSECS, 3600));
        $rows = $DB->get_records('auth_flexaccess_mailqueue',
            ['userid' => $user->id, 'mailtype' => mail_kind::PERSISTENCE_FOLLOWUP]);
        $this->assertCount(1, $rows);
        $row = reset($rows);
        $this->assertSame('queued', $row->status);
        // Clamped to expiry - margin.
        $this->assertSame($now + DAYSECS - 3600, (int) $row->nextrun);

        // Idempotent second call does not enqueue again.
        $this->assertFalse(\auth_flexaccess\api::request_persistence_followup($user->id, DAYSECS, 3600));
        $this->assertEquals(1, $DB->count_records('auth_flexaccess_mailqueue',
            ['userid' => $user->id, 'mailtype' => mail_kind::PERSISTENCE_FOLLOWUP]));
    }

    /** A converted (authenticated) user is not scheduled. */
    public function test_request_followup_skips_authenticated(): void {
        $this->resetAfterTest();
        $now = time();
        $user = $this->getDataGenerator()->create_user(['email' => 'real@example.com']);
        $this->make_account($user->id, account_type::AUTHENTICATED_USER, account_state::ACTIVE, $now, null);
        $this->assertFalse(\auth_flexaccess\api::request_persistence_followup($user->id, DAYSECS));
    }

    /** An account too short-lived to fit a reminder is not scheduled. */
    public function test_request_followup_skips_too_short(): void {
        $this->resetAfterTest();
        $now = time();
        $user = $this->getDataGenerator()->create_user(['email' => 'temp2@example.com']);
        $this->make_account($user->id, account_type::TEMPORARY_USER, account_state::EPHEMERAL,
            $now, $now + 1800);
        $this->assertFalse(\auth_flexaccess\api::request_persistence_followup($user->id, DAYSECS, 3600));
    }
}
