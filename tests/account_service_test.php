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
 * Tests for the FlexAccess account lifecycle service.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace auth_flexaccess;

use PHPUnit\Framework\Attributes\CoversClass;
use auth_flexaccess\local\account_service;
use auth_flexaccess\local\account_state;
use auth_flexaccess\local\account_type;

/**
 * Account service tests.
 *
 * @package    auth_flexaccess
 */
#[CoversClass(\auth_flexaccess\local\account_service::class)]
final class account_service_test extends \advanced_testcase {
    /**
     * Creating a temporary account stores temporary/ephemeral metadata.
     */
    public function test_create_temporary(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $id = account_service::create_temporary($user->id, 'REF-A', 2000, 5, 6, 1000);
        $row = $DB->get_record('auth_flexaccess_account', ['id' => $id], '*', MUST_EXIST);
        $this->assertSame(account_type::TEMPORARY_USER, $row->accounttype);
        $this->assertSame(account_state::EPHEMERAL, $row->accountstate);
        $this->assertSame(2000, (int) $row->timeexpires);
        $this->assertSame((int) $user->id, (int) $row->userid);
        $this->assertSame(account_type::TEMPORARY_USER, \auth_flexaccess\api::classify_user($user->id));
    }

    /**
     * Converting flips the type/state, clears expiry, confirms the user, and is idempotent.
     */
    public function test_convert_to_authenticated(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['confirmed' => 0]);
        account_service::create_temporary($user->id, 'REF-B', 5000, null, null, 1000);

        $this->assertTrue(account_service::convert_to_authenticated($user->id, 4000));
        $row = $DB->get_record('auth_flexaccess_account', ['userid' => $user->id], '*', MUST_EXIST);
        $this->assertSame(account_type::AUTHENTICATED_USER, $row->accounttype);
        $this->assertSame(account_state::ACTIVE, $row->accountstate);
        $this->assertSame(4000, (int) $row->timeactivated);
        $this->assertNull($row->timeexpires);
        $this->assertEquals(1, (int) $DB->get_field('user', 'confirmed', ['id' => $user->id]));
        $this->assertSame(account_type::AUTHENTICATED_USER, \auth_flexaccess\api::classify_user($user->id));

        // Idempotent: nothing left to convert.
        $this->assertFalse(account_service::convert_to_authenticated($user->id));
    }

    /**
     * Converting an unknown user does nothing.
     */
    public function test_convert_unknown_user(): void {
        $this->resetAfterTest();
        $this->assertFalse(account_service::convert_to_authenticated(999999));
    }

    /**
     * Expired temporary accounts are purged (user and artefacts deleted) after the retention window.
     *
     * @return void
     */
    public function test_purge_expired(): void {
        global $DB;
        $this->resetAfterTest();
        $now = 2000000;
        $old = $this->getDataGenerator()->create_user();
        $recent = $this->getDataGenerator()->create_user();
        // Both expired, but only $old is past the 10-day retention window.
        account_service::create_temporary($old->id, 'REF-OLD', $now - 20 * DAYSECS, null, null, $now - 30 * DAYSECS);
        account_service::create_temporary($recent->id, 'REF-NEW', $now - HOURSECS, null, null, $now - DAYSECS);
        account_service::expire_due($now);
        \auth_flexaccess\local\token_service::issue((int) $old->id, 'persistence', 3600, $now - 20 * DAYSECS);

        $purged = account_service::purge_expired($now, 10 * DAYSECS);
        $this->assertSame(1, $purged);
        $this->assertFalse($DB->record_exists('auth_flexaccess_account', ['userid' => $old->id]));
        $this->assertEquals(0, $DB->count_records('auth_flexaccess_token', ['userid' => $old->id]));
        $this->assertEquals(1, $DB->get_field('user', 'deleted', ['id' => $old->id]));
        // The recently expired account is retained.
        $this->assertTrue($DB->record_exists('auth_flexaccess_account', ['userid' => $recent->id]));
        // A zero retention disables purging.
        $this->assertSame(0, account_service::purge_expired($now, 0));
    }

    /**
     * is_convertible only accepts live temporary accounts.
     *
     * @return void
     */
    public function test_is_convertible(): void {
        $this->resetAfterTest();
        $now = 2000000;
        $live = $this->getDataGenerator()->create_user();
        $expired = $this->getDataGenerator()->create_user();
        $authed = $this->getDataGenerator()->create_user();
        account_service::create_temporary($live->id, 'REF-L', $now + DAYSECS, null, null, $now);
        account_service::create_temporary($expired->id, 'REF-E', $now - 10, null, null, $now - DAYSECS);
        account_service::create_authenticated($authed->id, 'REF-AU', $now);

        $this->assertTrue(account_service::is_convertible((int) $live->id, $now));
        $this->assertFalse(account_service::is_convertible((int) $expired->id, $now));
        $this->assertFalse(account_service::is_convertible((int) $authed->id, $now));
        $this->assertFalse(account_service::is_convertible(-1, $now));
    }

    /**
     * expire_due marks passed temporary accounts expired and suspends the user; others untouched.
     */
    public function test_expire_due(): void {
        global $DB;
        $this->resetAfterTest();
        $now = 1000000;

        $due = $this->getDataGenerator()->create_user();
        account_service::create_temporary($due->id, 'REF-DUE', $now - 10, null, null, $now - 1000);
        $future = $this->getDataGenerator()->create_user();
        account_service::create_temporary($future->id, 'REF-FUT', $now + 1000, null, null, $now - 1000);
        $noexpiry = $this->getDataGenerator()->create_user();
        account_service::create_temporary($noexpiry->id, 'REF-NOX', null, null, null, $now - 1000);

        $this->assertSame(1, account_service::expire_due($now));

        $this->assertSame(
            account_state::EXPIRED,
            $DB->get_field('auth_flexaccess_account', 'accountstate', ['userid' => $due->id])
        );
        $this->assertEquals(1, (int) $DB->get_field('user', 'suspended', ['id' => $due->id]));
        $this->assertSame(
            account_state::EPHEMERAL,
            $DB->get_field('auth_flexaccess_account', 'accountstate', ['userid' => $future->id])
        );
        $this->assertEquals(0, (int) $DB->get_field('user', 'suspended', ['id' => $future->id]));

        // Idempotent: a second run expires nothing more.
        $this->assertSame(0, account_service::expire_due($now));
    }

    /**
     * generate_unique_reference produces a 12-digit code that avoids existing references.
     *
     * @return void
     */
    public function test_generate_unique_reference(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        $ref = account_service::generate_unique_reference();
        $this->assertSame(12, strlen($ref));
        $this->assertSame(1, preg_match('/^\\d{12}$/', $ref));

        // A reference already in use is never returned.
        account_service::create_temporary($user->id, $ref, 2000, null, null, 1000);
        $this->assertNotSame($ref, account_service::generate_unique_reference());
    }

    /**
     * mark_provisional promotes an ephemeral account and leaves others untouched.
     *
     * @return void
     */
    public function test_mark_provisional(): void {
        $this->resetAfterTest();
        $now = time();
        $ephemeral = $this->getDataGenerator()->create_user();
        $active = $this->getDataGenerator()->create_user();
        account_service::create_temporary($ephemeral->id, 'REF-P1', $now + DAYSECS, null, null, $now);
        account_service::create_authenticated($active->id, 'REF-P2', $now);

        account_service::mark_provisional((int) $ephemeral->id, $now);
        account_service::mark_provisional((int) $active->id, $now);

        global $DB;
        $this->assertSame(
            account_state::PROVISIONAL,
            $DB->get_field('auth_flexaccess_account', 'accountstate', ['userid' => $ephemeral->id])
        );
        // An authenticated account is not affected.
        $this->assertSame(
            account_state::ACTIVE,
            $DB->get_field('auth_flexaccess_account', 'accountstate', ['userid' => $active->id])
        );
        // A provisional account remains convertible.
        $this->assertTrue(account_service::is_convertible((int) $ephemeral->id, $now));
    }
}
