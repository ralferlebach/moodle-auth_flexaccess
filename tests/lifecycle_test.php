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
use auth_flexaccess\local\lifecycle;

/**
 * Tests for the central lifecycle transitions and invariants (issue auth#5).
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \auth_flexaccess\local\lifecycle
 * @covers \auth_flexaccess\local\account_service
 */
final class lifecycle_test extends \advanced_testcase {
    /**
     * Skip when the restriction role (owned by enrol_flexaccess) is not available.
     *
     * @return void
     */
    private function require_enrol(): void {
        if (!class_exists('\enrol_flexaccess\local\participant_role')) {
            $this->markTestSkipped('Requires the enrol_flexaccess sibling plugin.');
        }
    }

    /**
     * Create an account in a given combination of stores.
     *
     * @param string $type Account type.
     * @param string $state Account state.
     * @param int $suspended user.suspended.
     * @param bool $restricted Whether the restriction role is held.
     * @param int|null $expires Account expiry.
     * @return int User id.
     */
    private function combo(string $type, string $state, int $suspended, bool $restricted, ?int $expires = null): int {
        global $DB;
        $user = $this->getDataGenerator()->create_user(['auth' => 'flexaccess', 'suspended' => $suspended]);
        $DB->insert_record('auth_flexaccess_account', (object) [
            'userid' => $user->id,
            'accounttype' => $type,
            'accountstate' => $state,
            'referencecode' => account_service::generate_unique_reference(),
            'timecreated' => time(),
            'timeexpires' => $expires,
            'timemodified' => time(),
        ]);
        if ($restricted) {
            \enrol_flexaccess\local\participant_role::restrict((int) $user->id);
        }
        return (int) $user->id;
    }

    /**
     * Codes the diagnosis reports for one user.
     *
     * @param int $userid User id.
     * @return string[]
     */
    private function codes(int $userid): array {
        $codes = array_map(static fn($m) => $m->code, lifecycle::find_state_mismatches(null, 50, [$userid]));
        sort($codes);
        return $codes;
    }

    /**
     * Matrix provider: accountstate x user.suspended x restriction role -> expected mismatches.
     *
     * @return array
     */
    public static function matrix_provider(): array {
        $t = account_type::TEMPORARY_USER;
        $a = account_type::AUTHENTICATED_USER;
        return [
            'ephemeral consistent' => [$t, account_state::EPHEMERAL, 0, true, []],
            'ephemeral suspended' => [$t, account_state::EPHEMERAL, 1, true, ['live_suspended']],
            'ephemeral unrestricted' => [$t, account_state::EPHEMERAL, 0, false, ['temporary_unrestricted']],
            'provisional consistent' => [$t, account_state::PROVISIONAL, 0, true, []],
            'expired consistent' => [$t, account_state::EXPIRED, 1, true, []],
            'expired unsuspended' => [$t, account_state::EXPIRED, 0, true, ['locked_unsuspended']],
            'pending consistent' => [$a, account_state::PENDING_CREDENTIAL, 0, true, []],
            'pending suspended' => [$a, account_state::PENDING_CREDENTIAL, 1, true, ['live_suspended']],
            'active consistent' => [$a, account_state::ACTIVE, 0, false, []],
            'active suspended' => [$a, account_state::ACTIVE, 1, false, ['active_suspended']],
            'active restricted' => [$a, account_state::ACTIVE, 0, true, ['active_restricted']],
            'active suspended restricted' => [$a, account_state::ACTIVE, 1, true, ['active_restricted', 'active_suspended']],
            'authenticated suspended state' => [$a, account_state::SUSPENDED, 1, false, []],
            'type/state contradiction' => [$t, account_state::ACTIVE, 0, true, ['type_state']],
        ];
    }

    /**
     * The diagnosis finds exactly the contradictions of the invariant table.
     *
     * @dataProvider matrix_provider
     * @param string $type Account type.
     * @param string $state Account state.
     * @param int $suspended user.suspended.
     * @param bool $restricted Restriction role held.
     * @param string[] $expected Expected mismatch codes.
     * @return void
     */
    public function test_invariant_matrix(string $type, string $state, int $suspended, bool $restricted, array $expected): void {
        $this->resetAfterTest();
        $this->require_enrol();
        $expires = $type === account_type::TEMPORARY_USER ? time() + 3600 : null;
        $userid = $this->combo($type, $state, $suspended, $restricted, $expires);
        sort($expected);
        $this->assertSame($expected, $this->codes($userid));
    }

    /**
     * Every transition leaves the account consistent, whatever inconsistent state it started from.
     *
     * @return void
     */
    public function test_transitions_write_the_complete_target_state(): void {
        global $DB;
        $this->resetAfterTest();
        $this->require_enrol();
        $userid = $this->combo(account_type::TEMPORARY_USER, account_state::EPHEMERAL, 1, false, time() + 3600);
        lifecycle::transition_to_pending_credential($userid);
        $this->assertSame([], $this->codes($userid));
        lifecycle::transition_to_active_authenticated($userid);
        $this->assertSame([], $this->codes($userid));
        $this->assertEquals(0, $DB->get_field('user', 'suspended', ['id' => $userid]));

        $temp = $this->combo(account_type::TEMPORARY_USER, account_state::EPHEMERAL, 0, true, time() - 1);
        $this->assertSame(['overdue'], $this->codes($temp));
        $this->assertSame(1, account_service::expire_due());
        $this->assertSame([], $this->codes($temp));
        $this->assertEquals(1, $DB->get_field('user', 'suspended', ['id' => $temp]));

        $this->assertSame(account_state::EPHEMERAL, lifecycle::transition_to_recovered_temporary($temp, 600));
        $this->assertSame([], $this->codes($temp));
    }

    /**
     * Regression: an otherwise convertible but suspended user becomes ACTIVE, unsuspended, unrestricted.
     *
     * @return void
     */
    public function test_conversion_of_suspended_user_is_normalised(): void {
        global $DB;
        $this->resetAfterTest();
        $this->require_enrol();
        $userid = $this->combo(account_type::TEMPORARY_USER, account_state::EPHEMERAL, 1, true, time() + 3600);
        $this->assertSame('converted', api::persist_temporary_user($userid, 'normal@example.com', 'N', 'U', 'Str0ng-Pass!23'));
        $account = api::get_account($userid);
        $this->assertSame(account_state::ACTIVE, $account->accountstate);
        $this->assertEquals(0, $DB->get_field('user', 'suspended', ['id' => $userid]));
        $this->assertSame([], $this->codes($userid));

        // The same holds for the administrative conversion once the credential is set.
        $admin = $this->combo(account_type::TEMPORARY_USER, account_state::EPHEMERAL, 1, true, time() + 3600);
        $this->assertSame('converted', api::admin_convert($admin, 'admin.conv@example.com'));
        $this->assertEquals(0, $DB->get_field('user', 'suspended', ['id' => $admin]));
        $token = local\token_service::issue($admin, 'setpassword', 600);
        $this->assertSame($admin, api::complete_set_password($token, 'Str0ng-Pass!23'));
        $this->assertSame(account_state::ACTIVE, api::get_account($admin)->accountstate);
        $this->assertSame([], $this->codes($admin));
    }

    /**
     * Repairs are explicit and limited to deterministic mismatches.
     *
     * @return void
     */
    public function test_explicit_repairs(): void {
        global $DB;
        $this->resetAfterTest();
        $this->require_enrol();
        $restricted = $this->combo(account_type::AUTHENTICATED_USER, account_state::ACTIVE, 0, true);
        $this->assertTrue(lifecycle::repair($restricted, lifecycle::MISMATCH_ACTIVE_RESTRICTED));
        $this->assertSame([], $this->codes($restricted));
        // Nothing left to repair: false.
        $this->assertFalse(lifecycle::repair($restricted, lifecycle::MISMATCH_ACTIVE_RESTRICTED));

        // Unsuspending an ACTIVE account grants access: never an automatic repair.
        $suspended = $this->combo(account_type::AUTHENTICATED_USER, account_state::ACTIVE, 1, false);
        $this->assertFalse(lifecycle::repair($suspended, lifecycle::MISMATCH_ACTIVE_SUSPENDED));
        $this->assertEquals(1, $DB->get_field('user', 'suspended', ['id' => $suspended]));

        // A restriction without any FlexAccess account (e.g. after a merge) is found and removable.
        $plain = $this->getDataGenerator()->create_user();
        \enrol_flexaccess\local\participant_role::restrict((int) $plain->id);
        $orphans = array_filter(
            lifecycle::find_state_mismatches(),
            static fn($m) => $m->code === lifecycle::MISMATCH_ORPHAN_RESTRICTION && $m->userid === (int) $plain->id
        );
        $this->assertCount(1, $orphans);
        $this->assertTrue(lifecycle::repair((int) $plain->id, lifecycle::MISMATCH_ORPHAN_RESTRICTION));
    }

    /**
     * Expiry ends running sessions of the now-locked user.
     *
     * @return void
     */
    public function test_expiry_ends_sessions(): void {
        global $DB;
        $this->resetAfterTest();
        $userid = $this->combo(account_type::TEMPORARY_USER, account_state::EPHEMERAL, 0, false, time() - 1);
        $DB->insert_record('sessions', (object) [
            'state' => 0, 'sid' => 'lifecycletest', 'sessdata' => null, 'userid' => $userid,
            'timecreated' => time(), 'timemodified' => time(), 'firstip' => '127.0.0.1', 'lastip' => '127.0.0.1',
        ]);
        account_service::expire_due();
        $this->assertFalse($DB->record_exists('sessions', ['userid' => $userid]));
    }
}
