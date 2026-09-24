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
use auth_flexaccess\local\account_type;
use auth_flexaccess\local\login_guard;

/**
 * Tests for the central FlexAccess login guard (issue auth#3).
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \auth_flexaccess\local\login_guard
 * @covers \auth_plugin_flexaccess
 */
final class login_guard_test extends \advanced_testcase {
    /**
     * Session creation reads the user agent, which the CLI test runner does not provide.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $_SERVER['HTTP_USER_AGENT'] = $_SERVER['HTTP_USER_AGENT'] ?? 'phpunit';
        $this->resetAfterTest();
        // Core only asks enabled auth plugins; without this every login would fail trivially.
        set_config('auth', 'flexaccess');
    }

    /** Password used for the test accounts. */
    private const PASSWORD = 'Str0ng-Pass!23';

    /**
     * Create a FlexAccess user with an account in the given type/state and a known password.
     *
     * @param string $type Account type.
     * @param string $state Account state.
     * @param array $user Extra user fields.
     * @param int|null $expires Account expiry.
     * @return \stdClass User record.
     */
    private function account(string $type, string $state, array $user = [], ?int $expires = null): \stdClass {
        global $DB;
        $user = $this->getDataGenerator()->create_user($user + ['auth' => 'flexaccess', 'password' => self::PASSWORD]);
        $DB->insert_record('auth_flexaccess_account', (object) [
            'userid' => $user->id,
            'accounttype' => $type,
            'accountstate' => $state,
            'referencecode' => account_service::generate_unique_reference(),
            'timecreated' => time(),
            'timeexpires' => $expires,
            'timemodified' => time(),
        ]);
        return $user;
    }

    /**
     * Try a real Moodle password login.
     *
     * @param \stdClass $user User.
     * @return bool
     */
    private function password_login(\stdClass $user): bool {
        $failure = 0;
        return (bool) authenticate_user_login($user->username, self::PASSWORD, false, $failure);
    }

    /**
     * Case 1: ACTIVE + user.suspended=0 may log in by password.
     *
     * @return void
     */
    public function test_active_unsuspended_password_login_allowed(): void {
        $this->resetAfterTest();
        $user = $this->account(account_type::AUTHENTICATED_USER, account_state::ACTIVE);
        $this->assertSame(login_guard::OK, login_guard::evaluate((int) $user->id, login_guard::CHANNEL_PASSWORD));
        $this->assertTrue($this->password_login($user));
    }

    /**
     * Case 2: ACTIVE + user.suspended=1 is refused by the FlexAccess plugin itself, with the reason logged.
     *
     * @return void
     */
    public function test_active_suspended_password_login_refused(): void {
        $this->resetAfterTest();
        $user = $this->account(account_type::AUTHENTICATED_USER, account_state::ACTIVE, ['suspended' => 1]);
        $this->assertSame(login_guard::REASON_SUSPENDED, login_guard::evaluate((int) $user->id, login_guard::CHANNEL_PASSWORD));
        // The plugin decides on its own, independently of core's suspended check.
        $plugin = get_auth_plugin('flexaccess');
        $sink = $this->redirectEvents();
        $this->assertFalse($plugin->user_login($user->username, self::PASSWORD));
        $refused = array_filter($sink->get_events(), static fn($e) => $e instanceof event\login_refused);
        $sink->close();
        $this->assertCount(1, $refused);
        $this->assertSame('suspended', reset($refused)->other['reason']);
        $this->assertFalse($this->password_login($user));
    }

    /**
     * Case 3: ACTIVE + user.suspended=1 receives no usable magic link and no session from a token.
     *
     * @return void
     */
    public function test_suspended_user_gets_no_magic_session(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $user = $this->account(account_type::AUTHENTICATED_USER, account_state::ACTIVE, [
            'email' => 'magic.suspended@example.com',
            'suspended' => 1,
        ]);
        // Request: the public answer is unchanged, but nothing is queued.
        $this->assertSame('sent', api::request_magic_login('magic.suspended@example.com'));
        $this->assertSame(0, $DB->count_records('auth_flexaccess_mailqueue', ['userid' => $user->id]));
        // Even a token minted earlier cannot be turned into a session.
        $token = local\token_service::issue((int) $user->id, 'magiclogin', 900);
        $this->assertNull(api::consume_magic_login($token));
        $this->assertFalse(api::complete_login((int) $user->id, login_guard::CHANNEL_MAGIC));
        $this->assertEquals(0, (int) $USER->id);
        // A delivery-time job for a meanwhile suspended account mints no link either.
        $DB->set_field('user', 'suspended', 0, ['id' => $user->id]);
        api::request_magic_login('magic.suspended@example.com');
        $DB->set_field('user', 'suspended', 1, ['id' => $user->id]);
        $sink = $this->redirectEmails();
        local\mail_worker::run(time());
        $this->assertSame(0, $sink->count());
        $sink->close();
        $this->assertFalse($DB->record_exists('auth_flexaccess_token', ['userid' => $user->id, 'timeused' => null]));
    }

    /**
     * Case 4: an EXPIRED account never logs in, on any channel.
     *
     * @return void
     */
    public function test_expired_account_refused(): void {
        $this->resetAfterTest();
        $user = $this->account(account_type::TEMPORARY_USER, account_state::EXPIRED, ['suspended' => 1]);
        foreach ([login_guard::CHANNEL_PASSWORD, login_guard::CHANNEL_MAGIC, login_guard::CHANNEL_ENTRY] as $channel) {
            $this->assertSame(login_guard::REASON_EXPIRED, login_guard::evaluate((int) $user->id, $channel));
        }
        $this->assertFalse($this->password_login($user));
        // Expired by time although the task has not run yet: refused as well.
        $late = $this->account(account_type::TEMPORARY_USER, account_state::EPHEMERAL, [], time() - 5);
        $this->assertSame(login_guard::REASON_EXPIRED, login_guard::evaluate((int) $late->id, login_guard::CHANNEL_ENTRY));
    }

    /**
     * Case 5: a SUSPENDED account never logs in, even with user.suspended=0 (state mismatch).
     *
     * @return void
     */
    public function test_suspended_state_refused(): void {
        $this->resetAfterTest();
        $user = $this->account(account_type::AUTHENTICATED_USER, account_state::SUSPENDED);
        $this->assertSame(login_guard::REASON_SUSPENDED, login_guard::evaluate((int) $user->id, login_guard::CHANNEL_PASSWORD));
        $this->assertFalse($this->password_login($user));
        $this->assertFalse(api::complete_login((int) $user->id, login_guard::CHANNEL_ENTRY));
    }

    /**
     * Case 6: a refused login while guest access is offered never yields a guest session.
     *
     * @return void
     */
    public function test_refused_login_does_not_fall_back_to_guest(): void {
        global $USER;
        $this->resetAfterTest();
        $user = $this->account(account_type::AUTHENTICATED_USER, account_state::ACTIVE, ['suspended' => 1]);
        $this->assertFalse(api::complete_login((int) $user->id, login_guard::CHANNEL_PASSWORD));
        $this->assertFalse(isguestuser());
        $this->assertEquals(0, (int) $USER->id);
    }

    /**
     * Case 7: the explicit guest button still leads to a guest session - but only where offered.
     *
     * @return void
     */
    public function test_explicit_guest_login_only_where_offered(): void {
        if (!class_exists(\enrol_flexaccess\api::class)) {
            $this->markTestSkipped('Requires the enrol_flexaccess sibling plugin.');
        }
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        // Not offered: no session.
        $this->assertFalse(login_guard::guest_login_allowed((int) $course->id));
        $this->assertFalse(api::complete_explicit_guest_login((int) $course->id));
        $this->assertFalse(isguestuser());

        // Offered: FlexAccess guest method on and a usable core guest enrolment.
        set_config('enrol_plugins_enabled', 'manual,guest,flexaccess');
        set_config('allowguest', 1, 'enrol_flexaccess');
        $enrolid = \enrol_flexaccess\local\enrol_service::ensure_instance((int) $course->id);
        global $DB;
        $DB->set_field('enrol_flexaccess_instance', 'allowguest', 1, ['enrolid' => $enrolid]);
        $guest = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'guest']);
        if ($guest) {
            $DB->set_field('enrol', 'status', ENROL_INSTANCE_ENABLED, ['id' => $guest->id]);
        } else {
            enrol_get_plugin('guest')->add_instance($course, ['status' => ENROL_INSTANCE_ENABLED]);
        }
        \cache::make('enrol_flexaccess', 'policy')->purge();
        if (!\enrol_flexaccess\api::offers_guest_access((int) $course->id)) {
            $this->markTestSkipped('Guest access could not be offered in this configuration.');
        }
        // The session itself needs a web request; the guard's decision is what is asserted here.
        $this->assertTrue(login_guard::guest_login_allowed((int) $course->id));
    }

    /**
     * Live temporary accounts only obtain the session of the entry flow, never a password session.
     *
     * @return void
     */
    public function test_temporary_account_channels(): void {
        $this->resetAfterTest();
        $user = $this->account(account_type::TEMPORARY_USER, account_state::PROVISIONAL, [], time() + 3600);
        $this->assertSame(login_guard::OK, login_guard::evaluate((int) $user->id, login_guard::CHANNEL_ENTRY));
        $this->assertSame(login_guard::REASON_INACTIVE, login_guard::evaluate((int) $user->id, login_guard::CHANNEL_PASSWORD));
        $this->assertSame(login_guard::REASON_INACTIVE, login_guard::evaluate((int) $user->id, login_guard::CHANNEL_MAGIC));
        $this->assertFalse(api::complete_login((int) $user->id, login_guard::CHANNEL_PASSWORD));
    }

    /**
     * Deleted, unconfirmed, non-FlexAccess and contradictory accounts are refused with their reason.
     *
     * @return void
     */
    public function test_other_refusal_reasons(): void {
        $this->resetAfterTest();
        $this->assertSame(login_guard::REASON_NOUSER, login_guard::evaluate(999999, login_guard::CHANNEL_PASSWORD));
        $deleted = $this->account(account_type::AUTHENTICATED_USER, account_state::ACTIVE);
        delete_user($deleted);
        $this->assertSame(login_guard::REASON_DELETED, login_guard::evaluate((int) $deleted->id, login_guard::CHANNEL_PASSWORD));
        $unconfirmed = $this->account(account_type::AUTHENTICATED_USER, account_state::ACTIVE, ['confirmed' => 0]);
        $this->assertSame(login_guard::REASON_UNCONFIRMED, login_guard::evaluate((int) $unconfirmed->id, 'password'));
        $manual = $this->getDataGenerator()->create_user();
        $this->assertSame(login_guard::REASON_NOACCOUNT, login_guard::evaluate((int) $manual->id, 'password'));
        $mismatch = $this->account(account_type::TEMPORARY_USER, account_state::ACTIVE);
        $this->assertSame(login_guard::REASON_STATEMISMATCH, login_guard::evaluate((int) $mismatch->id, 'password'));
    }

    /**
     * No page of this plugin may create a session except through the guard (LOGIN-004).
     *
     * Deliberately scans only this plugin: each sibling plugin that creates sessions carries its own
     * scan. Scanning the siblings here coupled this plugin's CI to whichever sibling version happened to
     * be installed alongside it.
     *
     * @return void
     */
    public function test_no_direct_session_creation_outside_the_guard(): void {
        $roots = [\core_component::get_component_directory('auth_flexaccess')];
        $offenders = [];
        foreach ($roots as $root) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                $path = $file->getPathname();
                if (substr($path, -4) !== '.php' || strpos($path, '/tests/') !== false || strpos($path, '/vendor/') !== false) {
                    continue;
                }
                if (substr($path, -strlen('classes/local/login_guard.php')) === 'classes/local/login_guard.php') {
                    continue;
                }
                // Strip comments so a mention in documentation is not mistaken for a call.
                $code = '';
                foreach (token_get_all((string) file_get_contents($path)) as $token) {
                    if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }
                    $code .= is_array($token) ? $token[1] : $token;
                }
                if (preg_match('/\bcomplete_user_login\s*\(/', $code)) {
                    $offenders[] = substr($path, strlen($root));
                }
            }
        }
        $this->assertSame([], $offenders, 'Direct complete_user_login() outside the login guard.');
    }
}
