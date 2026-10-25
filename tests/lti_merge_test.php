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
use auth_flexaccess\local\merge_guard;
use auth_flexaccess\local\merge_registry;
use auth_flexaccess\local\merge_service;

/**
 * Merging durable FlexAccess and LTI identities without losing login, bindings or grades (auth_flexaccess #13).
 *
 * The tests that merge through tool_mergeusers run against the installed plugin and are skipped when it
 * is not installed; the CI installs it so that they always run there.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \auth_flexaccess\local\merge_service
 * @covers \auth_flexaccess\local\merge_guard
 * @covers \auth_flexaccess\local\merge_registry
 * @covers \auth_flexaccess\local\lti_identity
 * @covers \auth_flexaccess\hook_callbacks
 * @covers \auth_flexaccess\observer
 */
final class lti_merge_test extends \advanced_testcase {
    /** @var \frozen_clock The scenario's frozen Moodle clock: every reference time of a test reads it. */
    private \frozen_clock $clock;

    /** Platform issuer. */
    private const ISS = 'https://platform.example.org';

    /** An accepted ownership proof (kind, evidence). */
    private const PROOF = ['reauthentication', 'Logged in with both accounts at the help desk, ticket 4711.'];

    /** @var \stdClass Course with a FlexAccess instance and a published LTI tool. */
    private \stdClass $course;

    /** @var \stdClass FlexAccess enrol instance. */
    private \stdClass $instance;

    /** @var \stdClass Published LTI 1.3 tool of the course. */
    private \stdClass $tool;

    /**
     * Course with FlexAccess and a published LTI 1.3 resource.
     */
    protected function setUp(): void {
        global $CFG, $DB;
        parent::setUp();
        $this->clock = $this->mock_clock_with_frozen();
        $this->resetAfterTest();
        local\login_guard::reset_guarded_logins();
        // The rollback of tool_mergeusers must be a real one: without this the test itself runs inside an
        // outer transaction and a refused merge could not be undone.
        $this->preventResetByRollback();
        // Approvals record the approving administrator (consent, auth_flexaccess #14).
        $this->setAdminUser();
        require_once($CFG->dirroot . '/auth/lti/auth.php');
        set_config('auth', 'flexaccess,lti');
        set_config('enrol_plugins_enabled', 'manual,flexaccess,lti');
        $this->course = $this->getDataGenerator()->create_course();
        $enrolid = \enrol_flexaccess\local\enrol_service::ensure_instance((int) $this->course->id);
        $this->instance = $DB->get_record('enrol', ['id' => $enrolid]);
        $this->tool = $this->getDataGenerator()->create_lti_tool((object) [
            'courseid' => $this->course->id,
            'ltiversion' => 'LTI-1p3',
            'provisioningmodelearner' => \auth_plugin_lti::PROVISIONING_MODE_AUTO_ONLY,
        ]);
    }

    /**
     * Skip when tool_mergeusers is not installed.
     *
     * @return void
     */
    private function require_mergeusers(): void {
        if (
            !class_exists('\tool_mergeusers\local\user_merger')
                || !class_exists('\tool_mergeusers\hook\after_merged_all_tables')
        ) {
            $this->markTestSkipped('tool_mergeusers with the after_merged_all_tables hook is not installed.');
        }
    }

    /**
     * Merge through tool_mergeusers as an administrator does.
     *
     * @param int $toid User to keep.
     * @param int $fromid User to remove.
     * @return bool Success.
     */
    private function mergeusers(int $toid, int $fromid): bool {
        $merger = new \tool_mergeusers\local\user_merger();
        [$success] = $merger->merge($toid, $fromid);
        return (bool) $success;
    }

    /**
     * Approve a pair with an accepted ownership proof (every merge needs one, auth_flexaccess #13).
     *
     * @param int $from Source.
     * @param int $to Survivor.
     * @return void
     */
    private function approve(int $from, int $to): void {
        $this->assertTrue(api::approve_merge($from, $to, true, ...self::PROOF));
    }

    /**
     * A durable FlexAccess account (password, ACTIVE) enrolled in the course.
     *
     * @param array $fields User fields.
     * @return int
     */
    private function durable(array $fields = []): int {
        $user = $this->getDataGenerator()->create_user(['auth' => 'flexaccess'] + $fields);
        update_internal_user_password($user, 'Durable-Pass!1');
        account_service::create_authenticated((int) $user->id, account_service::generate_unique_reference());
        $this->assertTrue(\enrol_flexaccess\local\enrol_service::admin_enrol((int) $this->course->id, (int) $user->id));
        return (int) $user->id;
    }

    /**
     * An auth_lti account created by an AUTO_ONLY launch: bound, member of the tool, with a grade.
     *
     * @param string $sub Platform subject.
     * @param string $iss Platform issuer.
     * @return int
     */
    private function lti_duplicate(string $sub, string $iss = self::ISS): int {
        global $DB;
        $userid = (int) $this->getDataGenerator()->create_user(['auth' => 'lti'])->id;
        (new \auth_plugin_lti())->create_user_binding($iss, $sub, $userid);
        $DB->insert_record('enrol_lti_users', (object) [
            'userid' => $userid,
            'toolid' => $this->tool->id,
            'sourceid' => $sub,
            'serviceurl' => 'https://platform.example.org/ags/lineitem',
            'timecreated' => $this->clock->time(),
        ]);
        return $userid;
    }

    /**
     * MERGE-LTI-001: a durable FlexAccess source is never merged into an LTI target (fail closed).
     */
    public function test_durable_flexaccess_into_lti_is_refused(): void {
        global $DB;
        $flex = $this->durable();
        $lti = $this->lti_duplicate('dup-1');
        // Reported after the fact (an older merge tool without the in-transaction hook): review, nothing changed.
        $result = merge_service::reconcile($flex, $lti);
        $this->assertSame(merge_registry::REVIEW, $result->status);
        $this->assertContains(merge_guard::SOURCE_DURABLE, $result->reasons);
        $this->assertTrue(
            $DB->record_exists('auth_flexaccess_account', ['userid' => $flex]),
            'The only password identity survives.'
        );
        $this->assertSame(0, $result->transferred);

        $this->require_mergeusers();
        $DB->delete_records(merge_registry::TABLE);
        $this->assertFalse($this->mergeusers($lti, $flex), 'tool_mergeusers rolls the merge back.');
        $this->assertTrue($DB->record_exists('auth_flexaccess_account', ['userid' => $flex]));
        $this->assertSame($lti, (int) (new \auth_plugin_lti())->get_user_binding(self::ISS, 'dup-1'));
        $this->assertTrue(validate_internal_user_password(\core_user::get_user($flex), 'Durable-Pass!1'));
    }

    /**
     * Two durable identities need a recorded approval; email alone never suffices.
     */
    public function test_merge_of_durable_identities_needs_approval(): void {
        $flex = $this->durable(['email' => 'same@example.org']);
        $lti = $this->lti_duplicate('dup-2');
        $assessment = api::assess_merge($lti, $flex);
        $this->assertTrue($assessment->needsapproval);
        $this->assertContains(merge_guard::NEEDS_APPROVAL, $assessment->blockers);
        $this->assertFalse(api::approve_merge($lti, $flex, false, ...self::PROOF), 'Approval requires the explicit confirmation.');
        $this->assertFalse(api::approve_merge($flex, $lti, true, ...self::PROOF), 'The disallowed direction cannot be approved.');
        $this->assertTrue(api::approve_merge($lti, $flex, true, ...self::PROOF));
        $this->assertSame([], api::assess_merge($lti, $flex)->blockers);
    }

    /**
     * LTI duplicate merged into the durable FlexAccess account: binding, membership and grade move, password stays.
     */
    public function test_lti_into_flexaccess_keeps_login_binding_and_grade_delivery(): void {
        global $DB;
        $this->require_mergeusers();
        $flex = $this->durable();
        $lti = $this->lti_duplicate('dup-3');
        // A second binding from another platform/deployment survives as well.
        (new \auth_plugin_lti())->create_user_binding('https://other.example.org', 'dup-3b', $lti);
        $item = $this->getDataGenerator()->create_grade_item(['courseid' => $this->course->id]);
        $DB->insert_record('grade_grades', (object) [
            'itemid' => $item->id,
            'userid' => $lti,
            'finalgrade' => 77,
            'rawgrade' => 77,
        ]);

        $this->assertFalse($this->mergeusers($flex, $lti), 'Without approval FlexAccess refuses inside the transaction.');
        $this->assertSame($lti, (int) (new \auth_plugin_lti())->get_user_binding(self::ISS, 'dup-3'));

        $this->assertTrue(api::approve_merge($lti, $flex, true, ...self::PROOF));
        $this->assertTrue($this->mergeusers($flex, $lti));
        $this->assertTrue(merge_registry::is_confirmed($lti, $flex), 'The tool_mergeusers log record is the evidence.');
        $auth = new \auth_plugin_lti();
        $this->assertSame($flex, (int) $auth->get_user_binding(self::ISS, 'dup-3'));
        $this->assertSame($flex, (int) $auth->get_user_binding('https://other.example.org', 'dup-3b'));
        $this->assertSame(0, $DB->count_records('enrol_lti_users', ['userid' => $lti]));
        $this->assertSame(1, $DB->count_records('enrol_lti_users', ['userid' => $flex, 'toolid' => $this->tool->id]));
        $this->assertEquals(77, $DB->get_field('grade_grades', 'finalgrade', ['itemid' => $item->id, 'userid' => $flex]));
        // The FlexAccess password login still works and the account is unchanged.
        $this->assertSame('flexaccess', $DB->get_field('user', 'auth', ['id' => $flex]));
        $this->assertTrue(validate_internal_user_password(\core_user::get_user($flex), 'Durable-Pass!1'));
        $this->assertSame('', local\login_guard::evaluate($flex, local\login_guard::CHANNEL_PASSWORD));
        $this->assertSame(merge_registry::RECONCILED, merge_registry::get($lti, $flex)->status);
        // The FlexAccess enrolment of the survivor is untouched.
        $this->assertSame(
            'ok',
            \enrol_flexaccess\api::effective_enrolment_state($flex, (int) $this->course->id)->state
        );
    }

    /**
     * Both members of the same published tool: the merge is refused, no grade-delivery mapping is lost.
     */
    public function test_duplicate_membership_is_refused(): void {
        global $DB;
        $this->require_mergeusers();
        $flex = $this->durable();
        $lti = $this->lti_duplicate('dup-4');
        $DB->insert_record('enrol_lti_users', (object) ['userid' => $flex, 'toolid' => $this->tool->id, 'serviceurl' => 'native']);
        $this->assertContains(merge_guard::DUPLICATE_LTI_USERS, api::assess_merge($lti, $flex)->warnings);
        $this->assertTrue(api::approve_merge($lti, $flex, true, ...self::PROOF));
        $this->assertFalse($this->mergeusers($flex, $lti));
        $this->assertSame(2, $DB->count_records('enrol_lti_users', ['toolid' => $this->tool->id]));
        $this->assertSame(1, $DB->count_records('enrol_lti_users', ['userid' => $lti]));
    }

    /**
     * A binding a merge tool left behind keeps the pair under review; after a merge tool_mergeusers
     * confirmed, the administrator's re-point moves it to the survivor and the relaunch lands there.
     */
    public function test_stale_binding_after_confirmed_merge_is_repointed(): void {
        global $DB;
        $this->require_mergeusers();
        $source = api::create_temporary_user($this->clock->time() + HOURSECS);
        enrol_get_plugin('flexaccess')->enrol_user($this->instance, $source, \enrol_flexaccess\local\participant_role::get_id());
        $target = $this->durable();
        $this->approve($source, $target);
        $this->assertTrue($this->mergeusers($target, $source));
        $this->assertTrue(merge_registry::is_confirmed($source, $target), 'tool_mergeusers logged the merge.');

        // A binding written for the merged-away user afterwards (a table the merge tool did not handle).
        (new \auth_plugin_lti())->create_user_binding(self::ISS, 'stale-1', $source);
        $result = merge_service::verify($source, $target);
        $this->assertSame(merge_registry::REVIEW, $result->status);
        $this->assertContains(merge_guard::SOURCE_BINDINGS, $result->reasons);
        $this->assertSame(1, api::count_merges_needing_review());

        // Without the ownership confirmation nothing moves.
        $refused = api::repoint_merged_lti_identity($source, $target, false);
        $this->assertSame('refused', $refused->status);
        $this->assertSame([local\lti_identity::REPOINT_OWNERSHIP], $refused->reasons);
        $this->assertSame($source, (int) (new \auth_plugin_lti())->get_user_binding(self::ISS, 'stale-1'));

        $repointed = api::repoint_merged_lti_identity($source, $target, true);
        $this->assertSame('repointed', $repointed->status);
        $this->assertSame(1, $repointed->bindings);
        $this->assertSame([], $repointed->reasons);
        $this->assertSame($target, (int) (new \auth_plugin_lti())->get_user_binding(self::ISS, 'stale-1'));
        $this->assertFalse($DB->record_exists('auth_flexaccess_account', ['userid' => $source]));
        $this->assertSame(merge_registry::RECONCILED, merge_registry::get($source, $target)->status);
        $this->assertSame(0, api::count_merges_needing_review());

        // Repeated request: nothing left to move, nothing changes.
        $again = api::repoint_merged_lti_identity($source, $target, true);
        $this->assertSame('nothingtodo', $again->status);

        // Relaunch: the platform identity resolves to the survivor.
        @(new \auth_plugin_lti())->complete_login(
            ['iss' => self::ISS, 'sub' => 'stale-1'],
            new \moodle_url('/'),
            \auth_plugin_lti::PROVISIONING_MODE_PROMPT_EXISTING_ONLY
        );
        global $USER;
        $this->assertSame($target, (int) $USER->id);
    }

    /**
     * auth_flexaccess #14: a registry row is no proof of a merge. Two live, unmerged accounts with a
     * "pending" or "review" row are never re-pointed, and neither LTI table changes.
     */
    public function test_repoint_refused_for_unmerged_pairs(): void {
        global $DB;
        $source = api::create_temporary_user($this->clock->time() + HOURSECS);
        (new \auth_plugin_lti())->create_user_binding(self::ISS, 'live-1', $source);
        $DB->insert_record('enrol_lti_users', (object) [
            'userid' => $source,
            'toolid' => $this->tool->id,
            'sourceid' => 'live-1',
            'timecreated' => $this->clock->time(),
        ]);
        $target = $this->durable();
        $snapshot = static fn(): array => [
            $DB->get_records('auth_lti_linked_login', null, 'id'),
            $DB->get_records('enrol_lti_users', null, 'id'),
        ];

        // Stage "pending": recorded without a merge.
        merge_registry::record($source, $target, 'admin');
        $before = $snapshot();
        $result = api::repoint_merged_lti_identity($source, $target, true);
        $this->assertSame('refused', $result->status);
        $this->assertContains(local\lti_identity::REPOINT_NOT_CONFIRMED, $result->reasons);
        $this->assertContains(local\lti_identity::REPOINT_SOURCE_ACTIVE, $result->reasons);
        $this->assertEquals($before, $snapshot());

        // Stage "review": a direct reconciliation of the live pair leaves it under review - still no proof.
        $reconciled = merge_service::reconcile($source, $target);
        $this->assertSame(merge_registry::REVIEW, $reconciled->status);
        $before = $snapshot();
        $result = api::repoint_merged_lti_identity($source, $target, true);
        $this->assertSame('refused', $result->status);
        $this->assertContains(local\lti_identity::REPOINT_NOT_CONFIRMED, $result->reasons);
        $this->assertEquals($before, $snapshot());
        $this->assertSame($source, (int) (new \auth_plugin_lti())->get_user_binding(self::ISS, 'live-1'));

        // A forged evidence id (a log record of another pair) is not accepted either.
        $DB->set_field(merge_registry::TABLE, 'mergelogid', 999999, ['fromuserid' => $source, 'touserid' => $target]);
        $pair = ['fromuserid' => $source, 'touserid' => $target];
        $DB->set_field(merge_registry::TABLE, 'timeconfirmed', $this->clock->time(), $pair);
        $this->assertFalse(merge_registry::is_confirmed($source, $target));
        $this->assertSame('refused', api::repoint_merged_lti_identity($source, $target, true)->status);
        $this->assertEquals($before, $snapshot());
    }

    /**
     * A membership conflict (the survivor already belongs to the source's tool) refuses the whole
     * repoint - also the bindings stay where they are - and the audit log records the outcome.
     */
    public function test_repoint_conflict_changes_nothing(): void {
        global $DB;
        $this->require_mergeusers();
        $source = api::create_temporary_user($this->clock->time() + HOURSECS);
        $target = $this->durable();
        $this->approve($source, $target);
        $this->assertTrue($this->mergeusers($target, $source));
        // Left behind for the source after the merge: a binding and a membership of a tool the survivor
        // already belongs to.
        (new \auth_plugin_lti())->create_user_binding(self::ISS, 'conflict-1', $source);
        foreach ([$target, $source] as $userid) {
            $DB->insert_record('enrol_lti_users', (object) [
                'userid' => $userid,
                'toolid' => $this->tool->id,
                'sourceid' => 'conflict-' . $userid,
                'timecreated' => $this->clock->time(),
            ]);
        }
        merge_service::verify($source, $target);
        $before = [$DB->get_records('auth_lti_linked_login', null, 'id'), $DB->get_records('enrol_lti_users', null, 'id')];

        $sink = $this->redirectEvents();
        $result = api::repoint_merged_lti_identity($source, $target, true);
        $events = array_values(array_filter($sink->get_events(), static fn($e) => $e instanceof event\identity_merge_action));
        $sink->close();
        $this->assertSame('conflict', $result->status);
        $this->assertSame([(int) $this->tool->id], $result->conflicts);
        $after = [$DB->get_records('auth_lti_linked_login', null, 'id'), $DB->get_records('enrol_lti_users', null, 'id')];
        $this->assertEquals($before, $after);
        $this->assertCount(1, $events);
        $this->assertSame('conflict', $events[0]->other['outcome']);
        $this->assertSame((int) merge_registry::get($source, $target)->mergelogid, $events[0]->other['mergelogid']);
        $this->assertGreaterThan(0, $events[0]->other['mergelogid']);
    }
    /**
     * MERGE-LTI-004: a deletion of the source before the queued reconciliation keeps the metadata it needs.
     */
    public function test_source_deleted_before_reconciliation(): void {
        global $DB;
        $source = api::create_temporary_user($this->clock->time() + HOURSECS);
        enrol_get_plugin('flexaccess')->enrol_user($this->instance, $source, \enrol_flexaccess\local\participant_role::get_id());
        $target = (int) $this->getDataGenerator()->create_user()->id;
        // The merge event is recorded first; the ad-hoc task has not run yet.
        $this->approve($source, $target);
        observer::user_merged($this->merged_event($target, $source));
        $this->assertSame(merge_registry::PENDING, merge_registry::get($source, $target)->status);

        delete_user(\core_user::get_user($source));
        $this->assertTrue($DB->record_exists('auth_flexaccess_account', ['userid' => $source]), 'Kept for the reconciliation.');

        ob_start();

        $this->runAdhocTasks(task\reconcile_merge::class);

        ob_end_clean();
        $this->assertSame(merge_registry::RECONCILED, merge_registry::get($source, $target)->status);
        $this->assertFalse($DB->record_exists('auth_flexaccess_account', ['userid' => $source]));
        // A retry is idempotent.
        $again = merge_service::verify($source, $target);
        $this->assertSame('reconciled', $again->status);
    }

    /**
     * Policy 9: an administrative suspension of the kept user, lifted by tool_mergeusers, is put back.
     */
    public function test_admin_lock_of_survivor_is_restored(): void {
        global $DB;
        $this->require_mergeusers();
        $source = api::create_temporary_user($this->clock->time() + HOURSECS);
        $target = $this->durable();
        $DB->set_field('user', 'suspended', 1, ['id' => $target]);
        $DB->set_field('auth_flexaccess_account', 'lockedby', local\lifecycle::LOCKED_BY_ADMIN, ['userid' => $target]);

        $this->approve($source, $target);
        $this->assertTrue($this->mergeusers($target, $source));
        // The own observer of tool_mergeusers unsuspends the kept user after the commit.
        $this->assertEquals(0, $DB->get_field('user', 'suspended', ['id' => $target]));
        ob_start();
        $this->runAdhocTasks(task\reconcile_merge::class);
        ob_end_clean();
        $this->assertEquals(1, $DB->get_field('user', 'suspended', ['id' => $target]));
        $row = merge_registry::get($source, $target);
        $this->assertSame(merge_registry::REVIEW, $row->status);
        $this->assertStringContainsString(merge_guard::TARGET_LOCKED, (string) $row->reasons);
    }

    /**
     * Upgrade backfill: earlier tool_mergeusers merges are registered and verified; stale bindings surface.
     */
    public function test_backfill_registers_earlier_merges(): void {
        global $CFG, $DB;
        $this->require_mergeusers();
        require_once($CFG->dirroot . '/auth/flexaccess/db/upgradelib.php');
        $target = $this->durable();
        $source = $this->lti_duplicate('old-1');
        // A merge from before 2026101700 that left the binding behind.
        $DB->insert_record('tool_mergeusers', (object) [
            'touserid' => $target,
            'fromuserid' => $source,
            'timemodified' => $this->clock->time() - DAYSECS,
            'log' => '[]',
            'status' => 'success',
        ]);
        $this->assertSame(1, auth_flexaccess_backfill_merges());
        $this->assertSame(0, auth_flexaccess_backfill_merges(), 'Idempotent.');
        ob_start();
        $this->runAdhocTasks(task\verify_merges::class);
        ob_end_clean();
        $row = merge_registry::get($source, $target);
        $this->assertNotSame(merge_registry::PENDING, $row->status);
        // A merge from before FlexAccess' check has no approval: it is a review case until an administrator
        // approves it afterwards with an ownership proof; then the stale binding surfaces.
        $this->assertSame(merge_guard::NEEDS_APPROVAL, $row->reasons);
        $this->approve($source, $target);
        $this->assertContains(merge_guard::SOURCE_BINDINGS, api::recheck_merge($source, $target)->reasons);
    }

    /**
     * Temporary visitor into a durable LTI identity (the original contract) still reconciles.
     */
    public function test_temporary_into_lti_identity_reconciles(): void {
        $source = api::create_temporary_user($this->clock->time() + HOURSECS);
        enrol_get_plugin('flexaccess')->enrol_user($this->instance, $source, \enrol_flexaccess\local\participant_role::get_id());
        $target = $this->lti_duplicate('keep-1');
        $this->assertSame(merge_registry::REVIEW, merge_service::reconcile($source, $target)->status, 'No proof yet.');
        $this->approve($source, $target);
        $result = merge_service::reconcile($source, $target);
        $this->assertSame('reconciled', $result->status);
        $this->assertSame(merge_registry::RECONCILED, merge_registry::get($source, $target)->status);
    }

    /**
     * Non-durable and stale bindings are found and can be removed; durable ones cannot.
     */
    public function test_binding_diagnosis_and_removal(): void {
        global $DB;
        $temporary = api::create_temporary_user($this->clock->time() + HOURSECS);
        (new \auth_plugin_lti())->create_user_binding(self::ISS, 'temp-1', $temporary);
        $durable = $this->durable();
        (new \auth_plugin_lti())->create_user_binding(self::ISS, 'durable-1', $durable);
        $gone = $this->lti_duplicate('gone-1');
        $DB->set_field('user', 'deleted', 1, ['id' => $gone]);

        $nondurable = api::lti_non_durable_bindings();
        $this->assertCount(1, $nondurable);
        $this->assertSame($temporary, (int) $nondurable[0]->userid);
        $stale = api::lti_stale_bindings();
        $this->assertCount(1, $stale);
        $this->assertSame($gone, (int) $stale[0]->userid);

        $durablebinding = (int) $DB->get_field('auth_lti_linked_login', 'id', ['userid' => $durable]);
        $this->assertFalse(api::remove_lti_binding($durablebinding));
        $this->assertTrue(api::remove_lti_binding((int) $nondurable[0]->bindingid));
        $this->assertTrue(api::remove_lti_binding((int) $stale[0]->bindingid));
        $this->assertSame([], api::lti_non_durable_bindings());
        $this->assertSame([], api::lti_stale_bindings());
    }

    /**
     * An event carrying tool_mergeusers' payload (other.usersinvolved), without requiring the plugin.
     *
     * @param int $toid Kept user.
     * @param int $fromid Removed user.
     * @return \core\event\base
     */
    private function merged_event(int $toid, int $fromid): \core\event\base {
        return \core\event\user_updated::create([
            'objectid' => $toid,
            'relateduserid' => $toid,
            'context' => \context_user::instance($toid),
            'other' => ['usersinvolved' => ['toid' => $toid, 'fromid' => $fromid]],
        ]);
    }

    /**
     * MERGE-LTI-001 for FlexAccess targets: a durable account is never merged into a FlexAccess account
     * without an own password (temporary here) - the password would be lost.
     */
    public function test_durable_flexaccess_into_temporary_flexaccess_is_refused(): void {
        global $DB;
        $flex = $this->durable();
        $temporary = \auth_flexaccess\api::create_temporary_user($this->clock->time() + HOURSECS);
        $assessment = merge_guard::assess($flex, $temporary);
        $this->assertContains(merge_guard::SOURCE_DURABLE, $assessment->blockers);

        $this->require_mergeusers();
        $this->assertFalse($this->mergeusers($temporary, $flex), 'tool_mergeusers rolls the merge back.');
        $this->assertTrue($DB->record_exists('auth_flexaccess_account', ['userid' => $flex]));
        $this->assertTrue(validate_internal_user_password(\core_user::get_user($flex), 'Durable-Pass!1'));
    }

    /**
     * Bindings are only re-pointed for a merge that happened and awaits review - an approval alone never
     * moves the bindings of a live identity.
     */
    public function test_repoint_requires_a_merge_under_review(): void {
        $flex = $this->durable(['email' => 'pair@example.org']);
        $lti = $this->lti_duplicate('pair-1');
        $this->setAdminUser();
        $this->assertTrue(\auth_flexaccess\api::approve_merge($lti, $flex, true, ...self::PROOF));
        $row = merge_registry::get($lti, $flex);
        $this->assertSame(merge_registry::PREAPPROVED, $row->status);
        $this->assertEquals(get_admin()->id, $row->approvedby);
        $result = \auth_flexaccess\local\lti_identity::repoint($lti, $flex, true);
        $this->assertSame('refused', $result->status, 'An approved, not merged pair must not be re-pointed.');
        $this->assertContains(\auth_flexaccess\local\lti_identity::REPOINT_NOT_CONFIRMED, $result->reasons);
        $this->assertSame($lti, (int) (new \auth_plugin_lti())->get_user_binding(self::ISS, 'pair-1'));
    }
}
