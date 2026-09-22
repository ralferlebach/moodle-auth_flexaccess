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

use auth_flexaccess\local\account_service;
use auth_flexaccess\local\account_state;
use auth_flexaccess\local\login_guard;

/**
 * Tests for the password login of temporary access-list accounts (BATCHLOGIN).
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \auth_flexaccess\local\login_guard
 * @covers \auth_flexaccess\local\lifecycle
 */
final class batch_login_test extends \advanced_testcase {
    /** Card password. */
    private const PASSWORD = 'Card-Pass!42';

    /**
     * Enable the plugin (core only asks enabled auth plugins) and provide a user agent.
     *
     * @return void
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        $_SERVER['HTTP_USER_AGENT'] = $_SERVER['HTTP_USER_AGENT'] ?? 'phpunit';
        set_config('auth', 'flexaccess');
        require_once($CFG->dirroot . '/auth/flexaccess/db/upgradelib.php');
    }

    /**
     * Real Moodle password login.
     *
     * @param string $username Username.
     * @return bool
     */
    private function login(string $username): bool {
        $failure = 0;
        return (bool) authenticate_user_login($username, self::PASSWORD, false, $failure);
    }

    /**
     * Case 1: a valid temporary access-list account logs in with its card credential.
     *
     * @return void
     */
    public function test_valid_batch_account_logs_in(): void {
        $userid = api::create_batch_account('card_valid', self::PASSWORD, 'A', 'B', false, time() + 3600);
        $this->assertEquals(1, api::get_account($userid)->batchcredential);
        if (class_exists('\enrol_flexaccess\local\participant_role')) {
            \enrol_flexaccess\local\participant_role::restrict($userid);
        }
        $before = api::get_account($userid);
        $this->assertTrue($this->login('card_valid'));
        // The login changes nothing about the lifecycle: still temporary, same expiry, restriction kept.
        $after = api::get_account($userid);
        $this->assertSame(account_state::EPHEMERAL, $after->accountstate);
        $this->assertEquals($before->timeexpires, $after->timeexpires);
        $this->assertSame([], api::find_state_mismatches(null, 10, [$userid]));
    }

    /**
     * Case 2: an expired access-list account is refused (by time and by state).
     *
     * @return void
     */
    public function test_expired_batch_account_refused(): void {
        $userid = api::create_batch_account('card_late', self::PASSWORD, 'A', 'B', false, time() - 1);
        $this->assertFalse($this->login('card_late'));
        account_service::expire_due();
        $this->assertSame(login_guard::REASON_EXPIRED, login_guard::evaluate($userid, login_guard::CHANNEL_PASSWORD));
    }

    /**
     * Case 3: a suspended Moodle user is refused.
     *
     * @return void
     */
    public function test_suspended_batch_account_refused(): void {
        global $DB;
        $userid = api::create_batch_account('card_blocked', self::PASSWORD, 'A', 'B', false, time() + 3600);
        $DB->set_field('user', 'suspended', 1, ['id' => $userid]);
        $this->assertSame(login_guard::REASON_SUSPENDED, login_guard::evaluate($userid, login_guard::CHANNEL_PASSWORD));
        $this->assertFalse($this->login('card_blocked'));
    }

    /**
     * Case 4: an anonymous temporary account with an (internal) password is still refused.
     *
     * @return void
     */
    public function test_anonymous_temporary_refused(): void {
        global $DB;
        $userid = api::create_temporary_user(time() + 3600);
        $user = $DB->get_record('user', ['id' => $userid]);
        update_internal_user_password($user, self::PASSWORD);
        $this->assertSame(login_guard::REASON_INACTIVE, login_guard::evaluate($userid, login_guard::CHANNEL_PASSWORD));
        $this->assertFalse($this->login($user->username));
    }

    /**
     * Case 5: a quick registration before verification (PROVISIONAL, password set) is refused.
     *
     * @return void
     */
    public function test_provisional_refused(): void {
        global $DB;
        $userid = api::create_temporary_user(time() + 3600);
        $this->assertSame('verificationsent', api::request_persistence($userid, 'prov@example.com', 'P', 'V', self::PASSWORD));
        $this->assertSame(account_state::PROVISIONAL, api::get_account($userid)->accountstate);
        // Even if it were wrongly flagged, a provisional account never qualifies.
        $DB->set_field('auth_flexaccess_account', 'batchcredential', 1, ['userid' => $userid]);
        $this->assertSame(login_guard::REASON_INACTIVE, login_guard::evaluate($userid, login_guard::CHANNEL_PASSWORD));
    }

    /**
     * Case 6: magic login stays unavailable for temporary access-list accounts.
     *
     * @return void
     */
    public function test_no_magic_login(): void {
        $userid = api::create_batch_account('card_magic', self::PASSWORD, 'A', 'B', false, time() + 3600);
        $this->assertSame(login_guard::REASON_INACTIVE, login_guard::evaluate($userid, login_guard::CHANNEL_MAGIC));
    }

    /**
     * Case 7: the upgrade marks existing temporary members, and nothing else.
     *
     * @return void
     */
    public function test_upgrade_backfill(): void {
        global $DB;
        if (!$DB->get_manager()->table_exists('tool_flexaccess_batch_member')) {
            $this->markTestSkipped('Requires the tool_flexaccess sibling plugin.');
        }
        $member = api::create_batch_account('card_old', self::PASSWORD, 'A', 'B', false, time() + 3600);
        $converted = api::create_batch_account('card_conv', self::PASSWORD, 'A', 'B', true);
        $anonymous = api::create_temporary_user(time() + 3600);
        // Simulate data from before 1.1.0: no flag set.
        $DB->set_field('auth_flexaccess_account', 'batchcredential', 0, []);
        $batchid = $DB->insert_record('tool_flexaccess_batch', (object) [
            'courseid' => SITEID, 'name' => 'old', 'permanent' => 0, 'membercount' => 2, 'timecreated' => time(),
        ]);
        $DB->insert_record('tool_flexaccess_batch_member', (object) [
            'batchid' => $batchid, 'userid' => $member, 'username' => 'card_old', 'converted' => 0,
        ]);
        $DB->insert_record('tool_flexaccess_batch_member', (object) [
            'batchid' => $batchid, 'userid' => $converted, 'username' => 'card_conv', 'converted' => 1,
        ]);
        $this->assertSame(1, auth_flexaccess_backfill_batchcredential());
        $this->assertEquals(1, api::get_account($member)->batchcredential);
        $this->assertEquals(0, api::get_account($converted)->batchcredential);
        $this->assertEquals(0, api::get_account($anonymous)->batchcredential);
        // Idempotent.
        $this->assertSame(0, auth_flexaccess_backfill_batchcredential());
    }

    /**
     * Case 8: converting an access-list account removes the flag.
     *
     * @return void
     */
    public function test_conversion_clears_flag(): void {
        $userid = api::create_batch_account('card_convert', self::PASSWORD, 'A', 'B', false, time() + 3600);
        $this->assertSame('converted', api::admin_convert($userid, 'card.person@example.com'));
        $this->assertEquals(0, api::get_account($userid)->batchcredential);
        // Pending credential: the card password does not work any more until the new one is set.
        $this->assertFalse($this->login('card_convert'));
    }
}
