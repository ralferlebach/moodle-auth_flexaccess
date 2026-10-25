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
use auth_flexaccess\local\lti_guard;

/**
 * LTI 1.3 first contact and bound launches of FlexAccess identities (auth_flexaccess #12).
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \auth_flexaccess\local\lti_guard
 * @covers \auth_flexaccess\hook_callbacks
 * @covers \auth_flexaccess\local\login_guard
 */
final class lti_binding_test extends \advanced_testcase {
    /** @var \frozen_clock The scenario's frozen Moodle clock: every reference time of a test reads it. */
    private \frozen_clock $clock;

    /** Platform issuer used throughout. */
    private const ISS = 'https://platform.example.org';

    /**
     * Enable FlexAccess and LTI authentication.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->clock = $this->mock_clock_with_frozen();
        $this->resetAfterTest();
        local\login_guard::reset_guarded_logins();
        require_once($CFG->dirroot . '/auth/lti/auth.php');
        set_config('auth', 'flexaccess,lti');
        set_config('enrol_plugins_enabled', 'manual,flexaccess');
    }

    /**
     * Restore the request globals the binding page check reads.
     */
    protected function tearDown(): void {
        global $SCRIPT;
        $SCRIPT = null;
        unset($_POST['existing_account']);
        parent::tearDown();
    }

    /**
     * A permanent, ACTIVE FlexAccess account with its own password.
     *
     * @param array $fields Extra user fields.
     * @return int User id.
     */
    private function durable(array $fields = []): int {
        $user = $this->getDataGenerator()->create_user(['auth' => 'flexaccess'] + $fields);
        update_internal_user_password($user, 'Durable-Pass!1');
        account_service::create_authenticated((int) $user->id, account_service::generate_unique_reference());
        return (int) $user->id;
    }

    /**
     * Simulate the request of auth/lti/login.php choosing "existing account" with a pending launch.
     *
     * @param string $sub Platform subject.
     * @return void
     */
    private function binding_request(string $sub): void {
        global $SCRIPT, $SESSION;
        $SCRIPT = lti_guard::BIND_SCRIPT;
        $_POST['existing_account'] = 1;
        $SESSION->auth_lti = (object) [
            'launchdata' => ['iss' => self::ISS, 'sub' => $sub],
            'returnurl' => new \moodle_url('/'),
            'provisioningmode' => \auth_plugin_lti::PROVISIONING_MODE_PROMPT_EXISTING_ONLY,
        ];
    }

    /**
     * Run what auth/lti/login.php does for "existing account", including Moodle's after_require_login callbacks.
     *
     * @param string $sub Platform subject.
     * @return void
     */
    private function bind_current_user(string $sub): void {
        global $CFG, $USER, $SESSION;
        require_once($CFG->dirroot . '/auth/flexaccess/lib.php');
        $this->binding_request($sub);
        auth_flexaccess_after_require_login();
        unset($SESSION->auth_lti);
        (new \auth_plugin_lti())->create_user_binding(self::ISS, $sub, (int) $USER->id);
    }

    /**
     * Native binding: a durable FlexAccess account is bound to (iss, sub) without a second user.
     */
    public function test_durable_account_is_bound_without_duplicate(): void {
        global $DB;
        $userid = $this->durable();
        $users = $DB->count_records('user');
        $this->setUser($userid);
        $this->bind_current_user('learner-1');

        $auth = new \auth_plugin_lti();
        $this->assertSame($userid, (int) $auth->get_user_binding(self::ISS, 'learner-1'));
        $this->assertSame($users, $DB->count_records('user'));
    }

    /**
     * A temporary FlexAccess session is never bound, and the pending launch is discarded.
     */
    public function test_temporary_session_is_not_bound(): void {
        global $CFG, $SESSION, $DB;
        require_once($CFG->dirroot . '/auth/flexaccess/lib.php');
        $temporary = api::create_temporary_user($this->clock->time() + HOURSECS);
        $this->setUser($temporary);
        $this->binding_request('visitor-1');
        $sink = $this->redirectEvents();
        try {
            auth_flexaccess_after_require_login();
            $this->fail('A temporary session must not be bound.');
        } catch (\moodle_exception $e) {
            $this->assertSame('ltibindrefused', $e->errorcode);
        }
        $events = array_values(array_filter($sink->get_events(), static fn($e): bool => $e instanceof event\login_refused));
        $sink->close();
        $this->assertTrue(empty($SESSION->auth_lti), 'The launch cannot be completed with this session later.');
        $this->assertCount(1, $events);
        $this->assertSame(lti_guard::CHANNEL_LTIBIND, $events[0]->other['channel']);
        $this->assertSame(lti_guard::REASON_TEMPORARY, $events[0]->other['reason']);
        $this->assertFalse($DB->record_exists('auth_lti_linked_login', ['userid' => $temporary]));
    }

    /**
     * The identity matrix: only a durable, ACTIVE, confirmed account with its own password qualifies.
     */
    public function test_identity_matrix(): void {
        global $DB;
        $now = $this->clock->time();
        $active = $this->durable();
        $this->assertSame('', lti_guard::durable_identity_problem($active, $now));

        $ephemeral = api::create_temporary_user($now + HOURSECS);
        $this->assertSame(lti_guard::REASON_TEMPORARY, lti_guard::durable_identity_problem($ephemeral, $now));

        $card = api::create_batch_account('card' . random_string(6), 'Card-Pass!123', 'Card', 'Holder', false, $now + HOURSECS);
        $this->assertSame(lti_guard::REASON_TEMPORARY, lti_guard::durable_identity_problem($card, $now));

        $pending = $this->durable();
        $DB->set_field('auth_flexaccess_account', 'accountstate', local\account_state::PENDING_CREDENTIAL, ['userid' => $pending]);
        $this->assertSame(login_guard::REASON_PENDINGCREDENTIAL, lti_guard::durable_identity_problem($pending, $now));

        $nopassword = $this->durable();
        $DB->set_field('user', 'password', '', ['id' => $nopassword]);
        $this->assertSame(lti_guard::REASON_NOCREDENTIAL, lti_guard::durable_identity_problem($nopassword, $now));

        $unconfirmed = $this->durable(['confirmed' => 0]);
        $this->assertSame(login_guard::REASON_UNCONFIRMED, lti_guard::durable_identity_problem($unconfirmed, $now));

        $locked = $this->durable();
        $DB->set_field('user', 'suspended', 1, ['id' => $locked]);
        $this->assertSame(login_guard::REASON_SUSPENDED, lti_guard::durable_identity_problem($locked, $now));

        $changing = $this->durable();
        set_user_preference('auth_flexaccess_pendingemail', 'new@example.org', $changing);
        $this->assertSame(lti_guard::REASON_VERIFICATIONPENDING, lti_guard::durable_identity_problem($changing, $now));

        // Accounts that are not FlexAccess' business always pass.
        $manual = (int) $this->getDataGenerator()->create_user()->id;
        $this->assertSame('', lti_guard::durable_identity_problem($manual, $now));
    }

    /**
     * PENDING_CREDENTIAL is refused on the binding page as well.
     */
    public function test_pending_credential_is_not_bound(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/auth/flexaccess/lib.php');
        $userid = $this->durable();
        $DB->set_field('auth_flexaccess_account', 'accountstate', local\account_state::PENDING_CREDENTIAL, ['userid' => $userid]);
        $this->setUser($userid);
        $this->binding_request('pending-1');
        $this->expectException(\moodle_exception::class);
        auth_flexaccess_after_require_login();
    }

    /**
     * Other pages and other users are not affected by the binding check.
     */
    public function test_binding_check_is_scoped(): void {
        global $CFG, $SCRIPT;
        require_once($CFG->dirroot . '/auth/flexaccess/lib.php');
        $temporary = api::create_temporary_user($this->clock->time() + HOURSECS);
        $this->setUser($temporary);
        $SCRIPT = '/course/view.php';
        $_POST['existing_account'] = 1;
        auth_flexaccess_after_require_login();
        $SCRIPT = lti_guard::BIND_SCRIPT;
        unset($_POST['existing_account']);
        auth_flexaccess_after_require_login();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->binding_request('manual-1');
        auth_flexaccess_after_require_login();
        $this->assertTrue(true, 'No refusal outside the binding request of a FlexAccess identity.');
    }

    /**
     * A bound account that expired before the expiry task ran cannot launch: the session is ended.
     */
    public function test_bound_launch_of_lapsed_account_is_refused(): void {
        global $DB;
        $temporary = api::create_temporary_user($this->clock->time() + HOURSECS);
        // A binding from before this policy (or created by other means) to a temporary identity.
        (new \auth_plugin_lti())->create_user_binding(self::ISS, 'legacy-1', $temporary);
        // Lapsed, but the scheduled task has not suspended it yet: Core's suspension check passes.
        $DB->set_field('auth_flexaccess_account', 'timeexpires', $this->clock->time() - 60, ['userid' => $temporary]);
        $this->assertEquals(0, $DB->get_field('user', 'suspended', ['id' => $temporary]));

        $sink = $this->redirectEvents();
        try {
            @(new \auth_plugin_lti())->complete_login(
                ['iss' => self::ISS, 'sub' => 'legacy-1'],
                new \moodle_url('/'),
                \auth_plugin_lti::PROVISIONING_MODE_PROMPT_EXISTING_ONLY
            );
            $this->fail('The lapsed identity must not get a session.');
        } catch (\moodle_exception $e) {
            $this->assertSame('externalloginrefused', $e->errorcode);
        }
        $refused = array_values(array_filter($sink->get_events(), static fn($e): bool => $e instanceof event\login_refused));
        $sink->close();
        $this->assertFalse(isloggedin());
        $this->assertCount(1, $refused);
        $this->assertSame(lti_guard::CHANNEL_EXTERNAL, $refused[0]->other['channel']);
    }

    /**
     * An administratively suspended FlexAccess account stays out via LTI (Core and FlexAccess agree).
     */
    public function test_bound_launch_of_suspended_account_is_refused(): void {
        global $DB;
        $userid = $this->durable();
        (new \auth_plugin_lti())->create_user_binding(self::ISS, 'locked-1', $userid);
        $DB->set_field('user', 'suspended', 1, ['id' => $userid]);
        $this->expectException(\moodle_exception::class);
        try {
            @(new \auth_plugin_lti())->complete_login(
                ['iss' => self::ISS, 'sub' => 'locked-1'],
                new \moodle_url('/'),
                \auth_plugin_lti::PROVISIONING_MODE_PROMPT_EXISTING_ONLY
            );
        } finally {
            $this->assertFalse(isloggedin());
        }
    }

    /**
     * A bound durable account launches normally; platform claims do not overwrite its identity.
     */
    public function test_bound_launch_of_durable_account(): void {
        global $USER, $DB;
        $userid = $this->durable(['email' => 'owner@example.org', 'firstname' => 'Real']);
        (new \auth_plugin_lti())->create_user_binding(self::ISS, 'learner-2', $userid);
        @(new \auth_plugin_lti())->complete_login(
            ['iss' => self::ISS, 'sub' => 'learner-2', 'email' => 'attacker@example.org', 'given_name' => 'Fake'],
            new \moodle_url('/'),
            \auth_plugin_lti::PROVISIONING_MODE_PROMPT_EXISTING_ONLY
        );
        $this->assertSame($userid, (int) $USER->id);
        $this->assertSame('owner@example.org', $DB->get_field('user', 'email', ['id' => $userid]));
        $this->assertSame('Real', $DB->get_field('user', 'firstname', ['id' => $userid]));
    }

    /**
     * FlexAccess' own guarded logins are not re-checked against the durable rule (temporary entry stays possible).
     */
    public function test_guarded_entry_login_is_not_refused(): void {
        global $USER;
        $temporary = api::create_temporary_user($this->clock->time() + HOURSECS);
        // Hide session header errors, as Core's own complete_user_login() test does.
        $this->assertTrue(@login_guard::complete_login($temporary, login_guard::CHANNEL_ENTRY));
        $this->assertSame($temporary, (int) $USER->id);
        $this->assertFalse(login_guard::consume_guarded_login($temporary), 'The mark is consumed by the hook.');
    }

    /**
     * An AUTO_ONLY first launch creates a separate auth_lti user; FlexAccess never links it by e-mail.
     */
    public function test_auto_only_creates_distinct_user_and_never_links_by_email(): void {
        global $USER, $DB;
        $userid = $this->durable(['email' => 'same@example.org']);
        @(new \auth_plugin_lti())->complete_login(
            [
                'iss' => self::ISS,
                'sub' => 'auto-1',
                'email' => 'same@example.org',
                'given_name' => 'Same',
                'family_name' => 'Person',
            ],
            new \moodle_url('/'),
            \auth_plugin_lti::PROVISIONING_MODE_AUTO_ONLY
        );
        $this->assertNotSame($userid, (int) $USER->id);
        $this->assertSame('lti', $DB->get_field('user', 'auth', ['id' => $USER->id]));
        $this->assertSame((int) $USER->id, (int) (new \auth_plugin_lti())->get_user_binding(self::ISS, 'auto-1'));
        // The duplicate is surfaced for the explicit merge workflow, never merged automatically.
        $candidates = api::lti_duplicate_candidates();
        $this->assertCount(1, $candidates);
        $this->assertSame($userid, (int) $candidates[0]->flexaccessuserid);
        $this->assertSame((int) $USER->id, (int) $candidates[0]->ltiuserid);
    }

    /**
     * A launch of an already signed-in bound user (auth_lti skips complete_user_login() then) is checked
     * on the launch page: a FlexAccess account that lapsed in the meantime is refused and signed out.
     */
    public function test_launch_with_existing_session_of_lapsed_account_is_refused(): void {
        global $CFG, $DB, $SCRIPT;
        require_once($CFG->dirroot . '/auth/flexaccess/lib.php');
        $userid = $this->durable();
        (new \auth_plugin_lti())->create_user_binding(self::ISS, 'session-1', $userid);
        $this->setUser($userid);
        $SCRIPT = '/enrol/lti/launch.php';
        // Allowed while the account is valid.
        auth_flexaccess_after_require_login();
        $this->assertTrue(isloggedin());

        // Expired, the scheduled task has not run yet, the session is still open.
        $DB->set_field('auth_flexaccess_account', 'timeexpires', $this->clock->time() - 60, ['userid' => $userid]);
        $DB->set_field('auth_flexaccess_account', 'accountstate', 'expired', ['userid' => $userid]);
        try {
            @auth_flexaccess_after_require_login();
            $this->fail('The lapsed identity must not keep its session through the launch.');
        } catch (\moodle_exception $e) {
            $this->assertSame('externalloginrefused', $e->errorcode);
        }
        $this->assertFalse(isloggedin());
        // Every other page returns after the script comparison.
        $SCRIPT = '/course/view.php';
        $this->setUser($userid);
        auth_flexaccess_after_require_login();
        $this->assertTrue(isloggedin());
    }

    /**
     * A user an administrator moved to another authentication method is no longer FlexAccess' decision,
     * even though the FlexAccess account row is still there.
     */
    public function test_user_with_other_auth_method_is_not_refused(): void {
        global $DB;
        $userid = $this->durable();
        $DB->set_field('user', 'auth', 'manual', ['id' => $userid]);
        $DB->set_field('auth_flexaccess_account', 'accountstate', 'expired', ['userid' => $userid]);
        $this->assertSame('', lti_guard::durable_identity_problem($userid));
        $this->assertSame('', @lti_guard::enforce_session($userid));
    }
}
