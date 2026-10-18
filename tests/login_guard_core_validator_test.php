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
use auth_flexaccess\local\login_guard;

/**
 * The login guard agrees with Moodle's own user validation (tool_flexaccess #11, COMPAT-004).
 *
 * Moodle 5.3 centralises the standard login checks in \core_auth\validate_user. FlexAccess keeps its own
 * guard (it must also judge FlexAccess lifecycle states, and run on 4.5), so these tests prove that the
 * guard refuses at least everything Core refuses: deleted, unconfirmed, suspended users and a disabled
 * auth plugin. On 5.3 the Core validator is asked for the same users as a cross-check.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \auth_flexaccess\local\login_guard
 */
final class login_guard_core_validator_test extends \advanced_testcase {
    /**
     * FlexAccess authentication enabled.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('auth', 'flexaccess');
    }

    /**
     * A durable FlexAccess account with a password.
     *
     * @param array $fields User fields.
     * @return \stdClass
     */
    private function account(array $fields = []): \stdClass {
        $user = $this->getDataGenerator()->create_user(['auth' => 'flexaccess'] + $fields);
        update_internal_user_password($user, 'Durable-Pass!1');
        account_service::create_authenticated((int) $user->id, account_service::generate_unique_reference());
        return \core_user::get_user($user->id);
    }

    /**
     * Whether Core's validator (5.3+) refuses the user for a web login (and an external login).
     *
     * @param \stdClass $user User record.
     * @return bool|null Null on Moodle versions without the validator.
     */
    private function core_refuses(\stdClass $user): ?bool {
        if (!class_exists('\core_auth\validate_user')) {
            return null;
        }
        $validator = \core\di::get(\core_auth\validate_user::class);
        try {
            $validator->validate_before_web_login($user);
            $validator->validate_before_external_login($user);
            return false;
        } catch (\core_auth\exception\access_denied_exception $e) {
            return true;
        }
    }

    /**
     * Every condition Core refuses is refused by the guard on every channel.
     */
    public function test_guard_refuses_what_core_refuses(): void {
        global $DB;
        $cases = [];
        $suspended = $this->account();
        $DB->set_field('user', 'suspended', 1, ['id' => $suspended->id]);
        $cases['suspended'] = [$suspended->id, login_guard::REASON_SUSPENDED];
        $unconfirmed = $this->account(['confirmed' => 0]);
        $cases['unconfirmed'] = [$unconfirmed->id, login_guard::REASON_UNCONFIRMED];
        $deleted = $this->account();
        $DB->set_field('user', 'deleted', 1, ['id' => $deleted->id]);
        $cases['deleted'] = [$deleted->id, login_guard::REASON_DELETED];

        foreach ($cases as $name => [$userid, $reason]) {
            foreach ([login_guard::CHANNEL_PASSWORD, login_guard::CHANNEL_MAGIC, login_guard::CHANNEL_ENTRY] as $channel) {
                $this->assertSame($reason, login_guard::evaluate((int) $userid, $channel), "$name via $channel");
            }
            $core = $this->core_refuses(\core_user::get_user($userid));
            if ($core !== null) {
                $this->assertTrue($core, "Core refuses $name as well");
            }
        }
    }

    /**
     * A disabled FlexAccess auth plugin blocks every login, as Core's validator does.
     */
    public function test_disabled_auth_plugin_blocks_login(): void {
        $user = $this->account();
        $this->assertSame(login_guard::OK, login_guard::evaluate((int) $user->id, login_guard::CHANNEL_PASSWORD));
        $this->assertFalse($this->core_refuses($user) ?? false);

        set_config('auth', '');
        foreach ([login_guard::CHANNEL_PASSWORD, login_guard::CHANNEL_MAGIC, login_guard::CHANNEL_ENTRY] as $channel) {
            $this->assertSame(login_guard::REASON_AUTHDISABLED, login_guard::evaluate((int) $user->id, $channel));
        }
        $core = $this->core_refuses($user);
        if ($core !== null) {
            $this->assertTrue($core);
        }
        // The password login through the auth plugin fails as well (fail closed).
        $this->assertFalse(get_auth_plugin('flexaccess')->user_login($user->username, 'Durable-Pass!1'));
    }

    /**
     * A refused login never turns into a guest session.
     */
    public function test_refused_login_does_not_fall_back_to_guest(): void {
        global $DB, $USER;
        $user = $this->account();
        $DB->set_field('user', 'suspended', 1, ['id' => $user->id]);
        $this->assertFalse(login_guard::complete_login((int) $user->id, login_guard::CHANNEL_PASSWORD));
        $this->assertFalse(isloggedin());
        $this->assertFalse(isguestuser($USER));
    }
}
