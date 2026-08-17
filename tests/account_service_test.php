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

use auth_flexaccess\local\account_service;
use auth_flexaccess\local\account_state;
use auth_flexaccess\local\account_type;

/**
 * Account service tests.
 */
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
}
