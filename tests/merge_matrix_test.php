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
 * Generic account consolidation: origin-type matrix, ownership proof, conflicts and real merges (auth #13).
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace auth_flexaccess;

use auth_flexaccess\local\account_service;
use auth_flexaccess\local\account_type;
use auth_flexaccess\local\merge_evidence;
use auth_flexaccess\local\merge_guard;
use auth_flexaccess\local\merge_policy;
use auth_flexaccess\local\merge_registry;

/**
 * Every merge runs through the installed tool_mergeusers (inside its transaction, with FlexAccess' hook and
 * table mergers), as an administrator would run it. Skipped without tool_mergeusers; the CI installs it.
 *
 * @package    auth_flexaccess
 * @covers \auth_flexaccess\local\merge_policy
 * @covers \auth_flexaccess\local\merge_guard
 * @covers \auth_flexaccess\local\merge_evidence
 * @covers \auth_flexaccess\local\merge_registry
 * @covers \auth_flexaccess\local\merge_service
 * @covers \auth_flexaccess\local\merge_coverage
 * @covers \auth_flexaccess\local\mergeusers\default_merger
 * @covers \auth_flexaccess\local\mergeusers\grade_grades_merger
 * @covers \auth_flexaccess\local\mergeusers\choice_answers_merger
 * @covers \auth_flexaccess\hook_callbacks
 */
final class merge_matrix_test extends \advanced_testcase {
    /** @var \frozen_clock The scenario's frozen Moodle clock: every reference time of a test reads it. */
    private \frozen_clock $clock;

    /** An accepted ownership proof (kind, evidence). */
    private const PROOF = ['documents', 'Student record 4711 and photo ID checked at the help desk.'];

    /** @var \stdClass Course with FlexAccess. */
    private \stdClass $course;

    /** @var \stdClass Second course with FlexAccess. */
    private \stdClass $other;

    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->clock = $this->mock_clock_with_frozen();
        require_once($CFG->dirroot . '/auth/lti/auth.php');
        $this->resetAfterTest();
        // The merge tool must really roll back a refused merge.
        $this->preventResetByRollback();
        $this->setAdminUser();
        set_config('auth', 'flexaccess,lti,oauth2');
        set_config('enrol_plugins_enabled', 'manual,flexaccess');
        $this->course = $this->getDataGenerator()->create_course();
        $this->other = $this->getDataGenerator()->create_course();
        foreach ([$this->course, $this->other] as $course) {
            \enrol_flexaccess\local\enrol_service::ensure_instance((int) $course->id);
        }
    }

    /**
     * Skip without tool_mergeusers.
     *
     * @return void
     */
    private function require_mergeusers(): void {
        if (!class_exists('\tool_mergeusers\local\user_merger')) {
            $this->markTestSkipped('tool_mergeusers is not installed.');
        }
    }

    /**
     * Merge through tool_mergeusers.
     *
     * @param int $toid User to keep.
     * @param int $fromid User to remove.
     * @return bool Success.
     */
    private function mergeusers(int $toid, int $fromid): bool {
        [$success] = (new \tool_mergeusers\local\user_merger())->merge($toid, $fromid);
        return (bool) $success;
    }

    /**
     * Approve a pair with an accepted proof.
     *
     * @param int $from Source.
     * @param int $to Survivor.
     * @return void
     */
    private function approve(int $from, int $to): void {
        $this->assertTrue(api::approve_merge($from, $to, true, ...self::PROOF), 'Approval recorded');
    }

    /**
     * A durable FlexAccess account with password, enrolled through FlexAccess in a course.
     *
     * @param \stdClass|null $course Course (default: the first one).
     * @return int
     */
    private function flexaccess(?\stdClass $course = null): int {
        $user = $this->getDataGenerator()->create_user(['auth' => 'flexaccess']);
        update_internal_user_password($user, 'Flex-Pass!1');
        account_service::create_authenticated((int) $user->id, account_service::generate_unique_reference());
        $courseid = (int) ($course ?? $this->course)->id;
        $this->assertTrue(\enrol_flexaccess\local\enrol_service::admin_enrol($courseid, (int) $user->id));
        return (int) $user->id;
    }

    /**
     * A local (manual) account with password.
     *
     * @return int
     */
    private function manual(): int {
        $user = $this->getDataGenerator()->create_user(['auth' => 'manual']);
        update_internal_user_password($user, 'Manual-Pass!1');
        return (int) $user->id;
    }

    /**
     * An account of an auth plugin FlexAccess does not know (an institutional login it cannot verify).
     *
     * @param string $auth Auth plugin name.
     * @return int
     */
    private function custom_auth(string $auth): int {
        global $DB;
        $userid = (int) $this->getDataGenerator()->create_user()->id;
        $DB->set_field('user', 'auth', $auth, ['id' => $userid]);
        return $userid;
    }

    /**
     * A provisional FlexAccess account (authenticated, still without password) with a FlexAccess enrolment.
     *
     * @return int
     */
    private function pending(): int {
        $userid = (int) $this->getDataGenerator()->create_user(['auth' => 'flexaccess'])->id;
        account_service::create_authenticated($userid, account_service::generate_unique_reference());
        return $this->enrolled($userid);
    }

    /**
     * Enrol a user through FlexAccess in the first course.
     *
     * @param int $userid User.
     * @return int The user id.
     */
    private function enrolled(int $userid): int {
        $this->assertTrue(\enrol_flexaccess\local\enrol_service::admin_enrol((int) $this->course->id, $userid));
        return $userid;
    }

    /**
     * A temporary FlexAccess visitor with a FlexAccess enrolment.
     *
     * @param int $expires Account expiry.
     * @return int
     */
    private function visitor(int $expires): int {
        $userid = api::create_temporary_user($expires, (int) $this->course->id);
        $this->assertTrue(\enrol_flexaccess\local\enrol_service::admin_enrol((int) $this->course->id, $userid, true));
        return $userid;
    }

    /**
     * A grade of a user for a grade item.
     *
     * @param int $itemid Grade item.
     * @param int $userid User.
     * @param float $grade Final grade.
     * @return void
     */
    private function grade(int $itemid, int $userid, float $grade): void {
        global $DB;
        $DB->insert_record('grade_grades', (object) [
            'itemid' => $itemid,
            'userid' => $userid,
            'finalgrade' => $grade,
            'rawgrade' => $grade,
        ]);
    }

    /**
     * Insert a row, filling every other NOT NULL column without default with 0 or ''.
     *
     * @param string $table Table.
     * @param array $values Values.
     * @return int Row id.
     */
    private function row(string $table, array $values): int {
        global $DB;
        $row = [];
        foreach ($DB->get_columns($table) as $name => $column) {
            if ($name === 'id') {
                continue;
            }
            if (array_key_exists($name, $values)) {
                $row[$name] = $values[$name];
            } else if ($column->not_null && !$column->has_default) {
                $row[$name] = in_array($column->meta_type, ['I', 'N', 'F', 'R'], true) ? 0 : '';
            }
        }
        return (int) $DB->insert_record($table, (object) $row);
    }

    /** @var int OAuth 2 issuer of the institutional accounts. */
    private int $issuerid = 0;

    /**
     * An institutional (OAuth 2) account bound to the identity provider by a linked login.
     *
     * @param bool $linked Whether the account has its linked login.
     * @return int
     */
    private function sso(bool $linked = true): int {
        global $DB;
        if (!$this->issuerid) {
            $issuer = new \core\oauth2\issuer(0, (object) [
                'name' => 'University IdP',
                'baseurl' => 'https://idp.example.org',
                'clientid' => 'flexaccess',
                'clientsecret' => 'secret',
                'enabled' => 1,
                'showonloginpage' => \core\oauth2\issuer::EVERYWHERE,
                'image' => '',
                'loginscopes' => 'openid profile email',
                'loginscopesoffline' => 'openid profile email',
                'loginparams' => '',
                'loginparamsoffline' => '',
                'alloweddomains' => '',
            ]);
            $issuer->create();
            $this->issuerid = (int) $issuer->get('id');
        }
        $user = $this->getDataGenerator()->create_user(['auth' => 'oauth2']);
        if ($linked) {
            $this->row('auth_oauth2_linked_login', [
                'userid' => $user->id,
                'issuerid' => $this->issuerid,
                'username' => 'idp-' . $user->id,
                'email' => $user->email,
                'confirmtoken' => '',
                'timecreated' => $this->clock->time(),
                'timemodified' => $this->clock->time(),
                'usermodified' => get_admin()->id,
            ] + (isset($DB->get_columns('auth_oauth2_linked_login')['confirmtokenexpires']) ? ['confirmtokenexpires' => 0] : []));
        }
        return (int) $user->id;
    }

    /**
     * Origins of every identity kind.
     */
    public function test_origin_classification(): void {
        $nologin = (int) $this->getDataGenerator()->create_user(['auth' => 'nologin'])->id;
        $sso = (int) $this->getDataGenerator()->create_user(['auth' => 'oauth2'])->id;
        $lti = (int) $this->getDataGenerator()->create_user(['auth' => 'lti'])->id;
        $pending = (int) $this->getDataGenerator()->create_user(['auth' => 'flexaccess'])->id;
        account_service::create_authenticated($pending, account_service::generate_unique_reference());
        $expected = [
            $this->visitor($this->clock->time() + HOURSECS) => merge_policy::EPHEMERAL,
            $pending => merge_policy::PENDING,
            $this->flexaccess() => merge_policy::FLEXACCESS,
            $this->manual() => merge_policy::LOCAL,
            $lti => merge_policy::LTI,
            $sso => merge_policy::INSTITUTIONAL,
            $nologin => merge_policy::NOLOGIN,
        ];
        foreach ($expected as $userid => $origin) {
            $this->assertSame($origin, merge_policy::origin(merge_guard::identity($userid)), "user $userid");
        }
        $this->assertSame(merge_policy::MISSING, merge_policy::origin(merge_guard::identity(-5)));
    }

    /**
     * The matrix (source -> survivor): blockers and whether an approval is needed.
     *
     * @return array
     */
    public static function matrix_provider(): array {
        $e = 'ephemeral';
        $f = 'flexaccess';
        $m = 'manual';
        $l = 'lti';
        $s = 'sso';
        return [
            'A: temporary -> FlexAccess' => [$e, $f, [], true],
            'A: temporary -> manual' => [$e, $m, [], true],
            'A: temporary -> SSO' => [$e, $s, [], true],
            'A: temporary -> LTI' => [$e, $l, [], true],
            'A: temporary -> temporary' => [$e, $e, [], true],
            'A: provisional -> FlexAccess' => ['pending', $f, [], true],
            'B: FlexAccess -> FlexAccess' => [$f, $f, [], true],
            'B: FlexAccess -> temporary' => [$f, $e, [merge_guard::SOURCE_DURABLE], true],
            'C: FlexAccess -> manual' => [$f, $m, [], true],
            'C: manual -> FlexAccess' => [$m, $f, [], true],
            'C: manual -> temporary' => [$m, $e, [merge_guard::SOURCE_DURABLE], true],
            'D: FlexAccess -> SSO' => [$f, $s, [], true],
            'D: SSO -> FlexAccess' => [$s, $f, [merge_policy::SOURCE_MANAGED], true],
            'D: SSO (FlexAccess enrolment) -> manual' => ['ssoenrolled', $m, [merge_policy::SOURCE_MANAGED], true],
            'D: SSO (FlexAccess enrolment) -> LTI' => ['ssoenrolled', $l, [merge_policy::SOURCE_MANAGED], true],
            'E: LTI -> FlexAccess' => [$l, $f, [], true],
            'E: FlexAccess -> LTI' => [$f, $l, [merge_guard::SOURCE_DURABLE], true],
            'E: LTI -> temporary' => [$l, $e, [merge_policy::WRONG_DIRECTION], true],
            'D: FlexAccess -> SSO without linked login' => [$f, 'ssounlinked', [merge_policy::SSO_UNVERIFIED], true],
            'D: FlexAccess -> unknown institutional login' => [$f, 'unknownsso', [merge_policy::SSO_UNSUPPORTED], true],
            'D: SSO (FlexAccess enrolment) -> other SSO' => ['ssoenrolled', 'ldap', [merge_policy::NO_SAFE_DIRECTION], true],
        ];
    }

    /**
     * Matrix verdicts, read before any merge.
     *
     * @dataProvider matrix_provider
     * @param string $source Source kind.
     * @param string $target Survivor kind.
     * @param string[] $blockers Expected blockers besides the approval.
     * @param bool $needsapproval Expected approval need.
     */
    public function test_matrix(string $source, string $target, array $blockers, bool $needsapproval): void {
        $make = function (string $kind): int {
            return match ($kind) {
                'ephemeral' => $this->visitor($this->clock->time() + HOURSECS),
                'flexaccess' => $this->flexaccess(),
                'manual' => $this->manual(),
                'lti' => (int) $this->getDataGenerator()->create_user(['auth' => 'lti'])->id,
                'sso' => $this->sso(),
                'ssounlinked' => $this->sso(false),
                'unknownsso' => $this->custom_auth('someidp'),
                'ldap' => (int) $this->getDataGenerator()->create_user(['auth' => 'ldap'])->id,
                'ssoenrolled' => $this->enrolled($this->sso()),
                'pending' => $this->pending(),
            };
        };
        $from = $make($source);
        $to = $make($target);
        $assessment = merge_guard::assess($from, $to);
        $this->assertTrue($assessment->involved);
        $this->assertSame($needsapproval, $assessment->needsapproval);
        $other = array_values(array_diff($assessment->blockers, [merge_guard::NEEDS_APPROVAL]));
        $this->assertEqualsCanonicalizing($blockers, $other);
        if ($blockers) {
            $this->assertFalse(api::approve_merge($from, $to, true, ...self::PROOF), 'A blocked direction cannot be approved.');
        }
    }

    /**
     * Ownership proofs: a known kind and evidence beyond e-mail addresses; legacy approvals without proof are void.
     */
    public function test_ownership_proof_is_required(): void {
        global $DB;
        $from = $this->manual();
        $to = $this->flexaccess();
        $this->assertFalse(api::approve_merge($from, $to, true), 'No proof.');
        $this->assertFalse(api::approve_merge($from, $to, true, 'email', 'Same address on both accounts, verified.'));
        $this->assertFalse(api::approve_merge($from, $to, true, 'documents', 'same@example.org other@example.org'));
        $this->assertFalse(api::approve_merge($from, $to, true, 'documents', 'checked'), 'Too short.');
        $this->assertFalse(api::approve_merge($from, $to, false, ...self::PROOF), 'No explicit confirmation.');
        $this->assertContains(merge_guard::NEEDS_APPROVAL, merge_guard::assess($from, $to)->blockers);
        $this->approve($from, $to);
        $row = merge_registry::get($from, $to);
        $this->assertSame('documents', $row->prooftype);
        $this->assertSame([], merge_guard::assess($from, $to)->blockers);
        // An approval as recorded before 2026102400 (no proof) is no approval.
        $DB->set_field(merge_registry::TABLE, 'prooftype', null, ['id' => $row->id]);
        $this->assertContains(merge_guard::NEEDS_APPROVAL, merge_guard::assess($from, $to)->blockers);
    }

    /**
     * C: a durable FlexAccess account merged into a local account - the local password stays, the FlexAccess
     * enrolment and the grade move, the FlexAccess lifecycle is archived before it is removed.
     */
    public function test_flexaccess_into_manual(): void {
        global $DB;
        $this->require_mergeusers();
        $flex = $this->flexaccess($this->other);
        $manual = $this->manual();
        $item = $this->getDataGenerator()->create_grade_item(['courseid' => $this->other->id]);
        $this->grade((int) $item->id, $flex, 61);
        $preview = merge_guard::assess($flex, $manual);
        $this->assertSame(['password'], [$preview->keptroute]);
        $this->assertSame([], $preview->lostroutes, 'Password login is replaced by the survivor\'s password login.');

        $this->assertFalse($this->mergeusers($manual, $flex), 'Without approval: rolled back.');
        $this->assertTrue($DB->record_exists('auth_flexaccess_account', ['userid' => $flex]));
        $this->approve($flex, $manual);
        $this->assertTrue($this->mergeusers($manual, $flex));

        $this->assertSame('manual', $DB->get_field('user', 'auth', ['id' => $manual]));
        $this->assertTrue(validate_internal_user_password(\core_user::get_user($manual), 'Manual-Pass!1'));
        $this->assertTrue(is_enrolled(\context_course::instance((int) $this->other->id), $manual, '', true));
        $this->assertEquals(61, $DB->get_field('grade_grades', 'finalgrade', ['itemid' => $item->id, 'userid' => $manual]));
        $row = merge_registry::get($flex, $manual);
        $this->assertSame(merge_registry::RECONCILED, $row->status);
        $details = json_decode($row->details, true);
        $this->assertSame(account_type::AUTHENTICATED_USER, $details['sourceaccount']['accounttype'], 'Archived lifecycle.');
        $this->assertSame(['from' => merge_policy::FLEXACCESS, 'to' => merge_policy::LOCAL], $details['origins']);
        $this->assertFalse($DB->record_exists('auth_flexaccess_account', ['userid' => $flex]));
    }

    /**
     * C reverse: a local account merged into the durable FlexAccess account - the FlexAccess login stays.
     */
    public function test_manual_into_flexaccess(): void {
        global $DB;
        $this->require_mergeusers();
        $flex = $this->flexaccess();
        $manual = $this->manual();
        $item = $this->getDataGenerator()->create_grade_item(['courseid' => $this->other->id]);
        $this->grade((int) $item->id, $manual, 70);
        $this->approve($manual, $flex);
        $this->assertTrue($this->mergeusers($flex, $manual));
        $this->assertTrue(validate_internal_user_password(\core_user::get_user($flex), 'Flex-Pass!1'));
        $this->assertSame('', local\login_guard::evaluate($flex, local\login_guard::CHANNEL_PASSWORD));
        $this->assertEquals(70, $DB->get_field('grade_grades', 'finalgrade', ['itemid' => $item->id, 'userid' => $flex]));
        $this->assertSame(merge_registry::RECONCILED, merge_registry::get($manual, $flex)->status);
    }

    /**
     * D: FlexAccess into an institutional account keeps the SSO survivor; the reverse is refused and rolled back.
     */
    public function test_flexaccess_and_institutional(): void {
        global $DB;
        $this->require_mergeusers();
        $flex = $this->flexaccess();
        $sso = $this->sso();
        $issuer = new \core\oauth2\issuer($this->issuerid);
        $this->assertSame($sso, (int) \auth_oauth2\api::match_username_to_user('idp-' . $sso, $issuer)->get('userid'));
        $this->assertFalse(api::approve_merge($sso, $flex, true, ...self::PROOF));
        $this->assertFalse($this->mergeusers($flex, $sso), 'A managed identity is never merged away.');
        $this->assertSame(merge_registry::REFUSED, merge_registry::get($sso, $flex)->status);
        $this->assertContains(merge_policy::SOURCE_MANAGED, explode(',', merge_registry::get($sso, $flex)->reasons));

        $this->approve($flex, $sso);
        $this->assertTrue($this->mergeusers($sso, $flex));
        $this->assertSame('oauth2', $DB->get_field('user', 'auth', ['id' => $sso]));
        $this->assertTrue(is_enrolled(\context_course::instance((int) $this->course->id), $sso, '', true));
        $this->assertSame(['password'], merge_guard::assess($flex, $sso)->lostroutes, 'The FlexAccess password ends.');
        $this->assertSame(merge_registry::RECONCILED, merge_registry::get($flex, $sso)->status);
        // The identity provider's login still resolves to the same, kept account (the lookup auth_oauth2 uses).
        $this->assertSame($sso, (int) \auth_oauth2\api::match_username_to_user('idp-' . $sso, $issuer)->get('userid'));
        $this->assertSame([], merge_guard::postconditions($flex, $sso, merge_registry::get($flex, $sso)));
    }

    /**
     * D: the kept institutional login is checked against the approval - a changed binding (here: the linked
     * login removed after the approval) refuses the merge; a username-based login that changed as well.
     */
    public function test_changed_institutional_binding_refuses_the_merge(): void {
        global $DB;
        $this->require_mergeusers();
        $flex = $this->flexaccess();
        $sso = $this->sso();
        $this->approve($flex, $sso);
        $DB->delete_records('auth_oauth2_linked_login', ['userid' => $sso]);
        $this->assertContains(merge_policy::SSO_UNVERIFIED, merge_guard::assess($flex, $sso)->blockers);
        $this->assertFalse($this->mergeusers($sso, $flex));
        $this->assertTrue($DB->record_exists('auth_flexaccess_account', ['userid' => $flex]));

        $other = $this->flexaccess();
        $ldap = (int) $this->getDataGenerator()->create_user(['auth' => 'ldap', 'username' => 'ldapuser'])->id;
        $this->approve($other, $ldap);
        $DB->set_field('user', 'username', 'renamed', ['id' => $ldap]);
        $this->assertContains(merge_policy::SSO_CHANGED, merge_guard::assess($other, $ldap)->blockers);
        $this->assertFalse($this->mergeusers($ldap, $other));
        $DB->set_field('user', 'username', 'ldapuser', ['id' => $ldap]);
        $this->assertTrue($this->mergeusers($ldap, $other), 'Unchanged binding: the merge goes ahead.');
        $this->assertSame('ldapuser', $DB->get_field('user', 'username', ['id' => $ldap]));
    }

    /**
     * B: two durable FlexAccess accounts with separate courses and a conflicting grade: both courses and both
     * grades survive (the conflicting one as evidence), the merge stays under review until acknowledged.
     */
    public function test_two_durable_flexaccess_accounts_with_conflict(): void {
        global $DB;
        $this->require_mergeusers();
        $keep = $this->flexaccess($this->course);
        $remove = $this->flexaccess($this->other);
        $shared = $this->getDataGenerator()->create_grade_item(['courseid' => $this->course->id]);
        $separate = $this->getDataGenerator()->create_grade_item(['courseid' => $this->other->id]);
        $this->grade((int) $shared->id, $keep, 90);
        $this->grade((int) $shared->id, $remove, 40);
        $this->grade((int) $separate->id, $remove, 55);
        // Both completed the shared course at different times.
        foreach ([$keep => 1000, $remove => 2000] as $userid => $time) {
            $DB->insert_record('course_completions', (object) [
                'userid' => $userid,
                'course' => $this->course->id,
                'timeenrolled' => $time,
                'timestarted' => $time,
                'timecompleted' => $time,
                'reaggregate' => 0,
            ]);
        }

        $preview = merge_guard::assess($remove, $keep);
        $this->assertSame(
            ['course_completions' => [(string) $this->course->id], 'grade_grades' => [(string) $shared->id]],
            $preview->conflicts
        );
        $this->assertContains(merge_guard::CONFLICTS_RECORDED, $preview->warnings);
        $this->approve($remove, $keep);
        $this->assertTrue($this->mergeusers($keep, $remove));

        foreach ([$this->course, $this->other] as $course) {
            $this->assertTrue(is_enrolled(\context_course::instance((int) $course->id), $keep, '', true));
        }
        $this->assertEquals(90, $DB->get_field('grade_grades', 'finalgrade', ['itemid' => $shared->id, 'userid' => $keep]));
        $this->assertEquals(55, $DB->get_field('grade_grades', 'finalgrade', ['itemid' => $separate->id, 'userid' => $keep]));
        $evidence = merge_evidence::for_pair($remove, $keep);
        $this->assertSame(['course_completions', 'grade_grades'], array_column($evidence, 'tablename'));
        $this->assertEquals(2000, json_decode($evidence[0]->sourcerows)[0]->timecompleted);
        $this->assertEquals(40, json_decode($evidence[1]->sourcerows)[0]->finalgrade, 'The overwritten grade is kept as evidence.');
        $this->assertEquals(90, json_decode($evidence[1]->targetrows)[0]->finalgrade);
        $this->assertSame(1, $DB->count_records('course_completions', ['course' => $this->course->id, 'userid' => $keep]));
        $row = merge_registry::get($remove, $keep);
        $this->assertSame(merge_registry::REVIEW, $row->status);
        $this->assertSame(merge_guard::CONFLICTS_RECORDED, $row->reasons);
        $this->assertTrue(validate_internal_user_password(\core_user::get_user($keep), 'Flex-Pass!1'));

        $result = api::acknowledge_merge_conflicts($remove, $keep);
        $this->assertSame(2, $result->acknowledged);
        $this->assertSame(merge_registry::RECONCILED, merge_registry::get($remove, $keep)->status);
    }

    /**
     * Both enrolled in the same FlexAccess instance: tool_mergeusers keeps the survivor's enrolment, FlexAccess
     * merges the source's entitlement into it (active wins, later end wins) - never more than either had.
     */
    public function test_shared_flexaccess_enrolment_keeps_the_better_entitlement(): void {
        global $DB;
        $this->require_mergeusers();
        $keep = $this->flexaccess();
        $remove = $this->flexaccess();
        $enrolid = (int) $DB->get_field('enrol', 'id', ['courseid' => $this->course->id, 'enrol' => 'flexaccess']);
        $later = $this->clock->time() + 30 * DAYSECS;
        $DB->set_field('user_enrolments', 'timeend', $this->clock->time() + DAYSECS, ['enrolid' => $enrolid, 'userid' => $keep]);
        $DB->set_field('user_enrolments', 'status', ENROL_USER_SUSPENDED, ['enrolid' => $enrolid, 'userid' => $keep]);
        $DB->set_field('user_enrolments', 'timeend', $later, ['enrolid' => $enrolid, 'userid' => $remove]);
        $this->approve($remove, $keep);
        $this->assertTrue($this->mergeusers($keep, $remove));
        $ue = $DB->get_record('user_enrolments', ['enrolid' => $enrolid, 'userid' => $keep], '*', MUST_EXIST);
        $this->assertEquals($later, $ue->timeend);
        $this->assertEquals(ENROL_USER_ACTIVE, $ue->status);
        $this->assertSame(1, $DB->count_records('user_enrolments', ['enrolid' => $enrolid]));
        $this->assertSame(merge_registry::RECONCILED, merge_registry::get($remove, $keep)->status);
        $this->assertSame([], merge_evidence::for_pair($remove, $keep), 'Resolved by the FlexAccess rule, no review.');
    }

    /**
     * A refused merge rolls its evidence back with it.
     */
    public function test_refused_merge_keeps_no_evidence(): void {
        $this->require_mergeusers();
        $keep = $this->flexaccess();
        $remove = $this->flexaccess();
        $item = $this->getDataGenerator()->create_grade_item(['courseid' => $this->course->id]);
        $this->grade((int) $item->id, $keep, 1);
        $this->grade((int) $item->id, $remove, 2);
        $this->assertFalse($this->mergeusers($keep, $remove), 'No approval.');
        $this->assertSame([], merge_evidence::for_pair($remove, $keep));
    }

    /**
     * A: a temporary visitor merged into another temporary visitor is absorbed without promotion: the survivor
     * stays temporary with its own expiry and restriction.
     */
    public function test_temporary_survivor_is_not_promoted(): void {
        global $DB;
        $this->require_mergeusers();
        $keepexpiry = $this->clock->time() + HOURSECS;
        $keep = $this->visitor($keepexpiry);
        $remove = $this->visitor($this->clock->time() + 30 * DAYSECS);
        $this->assertFalse($this->mergeusers($keep, $remove), 'No ownership proof: refused.');
        $this->approve($remove, $keep);
        $this->assertTrue($this->mergeusers($keep, $remove));
        $account = $DB->get_record('auth_flexaccess_account', ['userid' => $keep], '*', MUST_EXIST);
        $this->assertSame(account_type::TEMPORARY_USER, $account->accounttype);
        $this->assertEquals($keepexpiry, $account->timeexpires, 'The later expiry of the source is not taken over.');
        $restriction = \enrol_flexaccess\local\participant_role::get_restriction_id();
        $this->assertTrue(user_has_role_assignment($keep, $restriction, \context_system::instance()->id));
        $this->assertSame(merge_registry::RECONCILED, merge_registry::get($remove, $keep)->status);
    }

    /**
     * G: two accounts without FlexAccess state are none of FlexAccess' business.
     */
    public function test_pair_without_flexaccess_is_left_alone(): void {
        $this->require_mergeusers();
        $keep = $this->manual();
        $remove = $this->manual();
        $this->assertFalse(merge_guard::assess($remove, $keep)->involved);
        $this->assertSame([], merge_guard::assess($remove, $keep)->blockers);
        $this->assertTrue($this->mergeusers($keep, $remove));
        $this->assertNull(merge_registry::get($remove, $keep));
    }

    /**
     * F: three identities of one person - one deterministic survivor, every other merged into it (no chains);
     * a survivor that was merged away and a source merged twice are refused.
     */
    public function test_three_identities(): void {
        global $DB;
        $this->require_mergeusers();
        $visitor = $this->visitor($this->clock->time() + HOURSECS);
        $flex = $this->flexaccess();
        $lti = (int) $this->getDataGenerator()->create_user(['auth' => 'lti'])->id;
        (new \auth_plugin_lti())->create_user_binding('https://platform.example.org', 'sub-3', $lti);

        $plan = merge_policy::plan([$lti, $visitor, $flex]);
        $this->assertEquals($plan, merge_policy::plan([$flex, $lti, $visitor]), 'Independent of the order.');
        $this->assertSame($flex, $plan->survivor);
        $this->assertFalse($plan->review);
        $this->assertSame([$visitor, $lti], array_map(static fn($s): int => $s->from, $plan->steps));
        $this->assertSame([true, true], array_map(static fn($s): bool => $s->needsapproval, $plan->steps));

        foreach ($plan->steps as $step) {
            $this->assertFalse($this->mergeusers($step->to, $step->from), 'step ' . $step->from . ' without proof');
            $this->approve($step->from, $step->to);
            $this->assertTrue($this->mergeusers($step->to, $step->from), 'step ' . $step->from);
        }
        $this->assertSame($flex, (int) (new \auth_plugin_lti())->get_user_binding('https://platform.example.org', 'sub-3'));
        $this->assertSame(merge_registry::RECONCILED, merge_registry::get($visitor, $flex)->status);
        $this->assertSame(merge_registry::RECONCILED, merge_registry::get($lti, $flex)->status);
        $this->assertSame(1, $DB->count_records('user_enrolments', ['userid' => $flex]));

        // No chains: nothing is merged into an identity that was merged away, and no source twice.
        $late = $this->visitor($this->clock->time() + HOURSECS);
        $this->assertContains(merge_policy::TARGET_MERGED_AWAY, merge_guard::assess($late, $visitor)->blockers);
        $other = $this->flexaccess();
        $this->assertContains(merge_policy::SOURCE_ALREADY_MERGED, merge_guard::assess($lti, $other)->blockers);
        // Two managed identities of different auth plugins: no safe survivor.
        $ldap = (int) $this->getDataGenerator()->create_user(['auth' => 'ldap'])->id;
        $this->assertTrue(merge_policy::plan([$ldap, $this->sso(), $this->flexaccess()])->review);
    }

    /**
     * The queued reconciliation and the deletion of the merged-away user may come in either order.
     */
    public function test_deletion_and_task_in_either_order(): void {
        global $DB;
        $this->require_mergeusers();
        foreach (['task first', 'deletion first'] as $order) {
            $keep = $this->flexaccess();
            $remove = $this->visitor($this->clock->time() + HOURSECS);
            $this->approve($remove, $keep);
            $this->assertTrue($this->mergeusers($keep, $remove));
            $steps = [
                fn() => delete_user(\core_user::get_user($remove)),
                function () {
                    ob_start();
                    $this->runAdhocTasks(task\reconcile_merge::class);
                    ob_end_clean();
                },
            ];
            if ($order === 'task first') {
                $steps = array_reverse($steps);
            }
            foreach ($steps as $step) {
                $step();
            }
            $this->assertSame(merge_registry::RECONCILED, merge_registry::get($remove, $keep)->status, $order);
            $this->assertFalse($DB->record_exists('auth_flexaccess_account', ['userid' => $remove]), $order);
            $this->assertSame('reconciled', local\merge_service::verify($remove, $keep)->status, $order . ': retry');
        }
    }

    /**
     * N1: a temporary source without an ownership proof is refused and nothing of either user changes; with a
     * proof it is absorbed without any escalation of the survivor (no restriction, no expiry, same state).
     */
    public function test_temporary_source_needs_an_ownership_proof(): void {
        global $DB;
        $this->require_mergeusers();
        $visitor = $this->visitor($this->clock->time() + HOURSECS);
        $keep = $this->flexaccess($this->other);
        $item = $this->getDataGenerator()->create_grade_item(['courseid' => $this->course->id]);
        $this->grade((int) $item->id, $visitor, 33);
        $snapshot = fn(): array => [
            $DB->get_records('user_enrolments', ['userid' => $visitor], 'id', 'id, enrolid, status, timeend'),
            $DB->get_records('user_enrolments', ['userid' => $keep], 'id', 'id, enrolid, status, timeend'),
            $DB->get_record('auth_flexaccess_account', ['userid' => $visitor], 'accounttype, accountstate, timeexpires'),
            $DB->get_record('auth_flexaccess_account', ['userid' => $keep], 'accounttype, accountstate, timeexpires'),
            $DB->get_field('grade_grades', 'userid', ['itemid' => $item->id]),
        ];
        $before = $snapshot();
        $this->assertContains(merge_guard::NEEDS_APPROVAL, merge_guard::assess($visitor, $keep)->blockers);
        $this->assertFalse($this->mergeusers($keep, $visitor));
        $this->assertEquals($before, $snapshot(), 'Both users unchanged.');
        $this->assertSame(merge_registry::REFUSED, merge_registry::get($visitor, $keep)->status);
        $this->assertStringContainsString(merge_guard::NEEDS_APPROVAL, merge_registry::get($visitor, $keep)->reasons);

        $this->approve($visitor, $keep);
        $this->assertTrue($this->mergeusers($keep, $visitor));
        $account = $DB->get_record('auth_flexaccess_account', ['userid' => $keep], '*', MUST_EXIST);
        $this->assertSame(account_type::AUTHENTICATED_USER, $account->accounttype);
        $this->assertEmpty($account->timeexpires, 'The temporary expiry is not taken over.');
        $restriction = \enrol_flexaccess\local\participant_role::get_restriction_id();
        $this->assertFalse(user_has_role_assignment($keep, $restriction, \context_system::instance()->id));
        $this->assertEquals(33, $DB->get_field('grade_grades', 'finalgrade', ['itemid' => $item->id, 'userid' => $keep]));
        $this->assertSame(merge_registry::RECONCILED, merge_registry::get($visitor, $keep)->status);
    }

    /**
     * N1: a provisional account (no password yet) needs a proof as well; holding a temporary session of one
     * account is no proof - a logged-in visitor cannot approve its own merge into somebody else's account.
     */
    public function test_session_holder_cannot_approve(): void {
        $this->require_mergeusers();
        $pending = $this->pending();
        $keep = $this->flexaccess();
        $this->assertFalse($this->mergeusers($keep, $pending), 'Provisional source without proof.');

        $visitor = $this->visitor($this->clock->time() + HOURSECS);
        $this->setUser($visitor);
        $this->assertFalse(api::approve_merge($visitor, $keep, true, ...self::PROOF), 'No right to approve.');
        $this->setAdminUser();
        $this->assertFalse(merge_registry::is_approved($visitor, $keep));
        $this->assertFalse($this->mergeusers($keep, $visitor));
        $this->assertSame(merge_registry::REFUSED, merge_registry::get($visitor, $keep)->status);
    }

    /**
     * N2: a recorded FlexAccess enrolment whose survivor enrolment is gone is not marked as done - the merge stays
     * under review with the values kept; after the survivor's enrolment is restored a re-check merges it once.
     */
    public function test_recorded_enrolment_without_survivor_enrolment_stays_open(): void {
        global $DB;
        $keep = $this->flexaccess();
        $remove = $this->flexaccess();
        $enrolid = (int) $DB->get_field('enrol', 'id', ['courseid' => $this->course->id, 'enrol' => 'flexaccess']);
        $later = $this->clock->time() + 20 * DAYSECS;
        $DB->set_field('user_enrolments', 'timeend', $this->clock->time() + DAYSECS, ['enrolid' => $enrolid, 'userid' => $keep]);
        $DB->set_field('user_enrolments', 'timeend', $later, ['enrolid' => $enrolid, 'userid' => $remove]);
        // What happens inside the merge: the source's enrolment is recorded, then removed by the generic merge.
        $this->assertSame(1, merge_evidence::capture_enrolments($remove, $keep));
        $this->assertSame(1, merge_evidence::capture_enrolments($remove, $keep) + 1, 'Recorded once.');
        $DB->delete_records('user_enrolments', ['enrolid' => $enrolid, 'userid' => $remove]);
        // An inconsistent run: the survivor's enrolment is missing as well.
        $instance = $DB->get_record('enrol', ['id' => $enrolid], '*', MUST_EXIST);
        enrol_get_plugin('flexaccess')->unenrol_user($instance, $keep);
        $this->approve($remove, $keep);

        $result = local\merge_service::reconcile($remove, $keep, false, null, 'admin');
        $this->assertSame(merge_registry::REVIEW, $result->status);
        $this->assertContains(merge_guard::ENROLMENTS_PENDING, $result->reasons);
        $this->assertSame([$enrolid], merge_evidence::pending_enrolments($remove, $keep), 'Not acknowledged.');
        $this->assertTrue($DB->record_exists('auth_flexaccess_account', ['userid' => $remove]), 'Source data kept.');
        $details = json_decode(merge_registry::get($remove, $keep)->details, true);
        $this->assertSame([$enrolid], $details['pendingenrolments']);

        // A replay changes nothing while the state is still wrong.
        $again = local\merge_service::verify($remove, $keep);
        $this->assertContains(merge_guard::ENROLMENTS_PENDING, $again->reasons);

        // The administrator restores the survivor's enrolment: the next re-check merges the recorded values once.
        enrol_get_plugin('flexaccess')->enrol_user($instance, $keep, null, 0, $this->clock->time() + DAYSECS);
        $fixed = local\merge_service::verify($remove, $keep);
        $this->assertSame('reconciled', $fixed->status);
        $ue = $DB->get_record('user_enrolments', ['enrolid' => $enrolid, 'userid' => $keep], '*', MUST_EXIST);
        $this->assertEquals($later, $ue->timeend, 'The later, limited end - not unlimited.');
        $this->assertSame(1, $DB->count_records('user_enrolments', ['enrolid' => $enrolid, 'userid' => $keep]));
        $this->assertSame([], merge_evidence::pending_enrolments($remove, $keep));
        $details = json_decode(merge_registry::get($remove, $keep)->details, true);
        $this->assertEquals($later, $details['enrolmentmerges'][$enrolid]['after']['timeend']);
        // Idempotent: another re-check changes nothing.
        $this->assertSame('reconciled', local\merge_service::verify($remove, $keep)->status);
        $this->assertEquals($later, $DB->get_field('user_enrolments', 'timeend', ['enrolid' => $enrolid, 'userid' => $keep]));
        $this->assertSame(1, $DB->count_records('user_enrolments', ['enrolid' => $enrolid, 'userid' => $keep]));
    }

    /**
     * N2: a recorded enrolment whose FlexAccess instance no longer exists keeps the merge under review; only an
     * administrator's documented decision closes it.
     */
    public function test_recorded_enrolment_without_instance_needs_a_decision(): void {
        global $DB;
        $keep = $this->flexaccess();
        $remove = $this->flexaccess();
        $enrolid = (int) $DB->get_field('enrol', 'id', ['courseid' => $this->course->id, 'enrol' => 'flexaccess']);
        merge_evidence::capture_enrolments($remove, $keep);
        $instance = $DB->get_record('enrol', ['id' => $enrolid], '*', MUST_EXIST);
        enrol_get_plugin('flexaccess')->delete_instance($instance);
        $this->approve($remove, $keep);
        $result = local\merge_service::reconcile($remove, $keep, false, null, 'admin');
        $this->assertSame(merge_registry::REVIEW, $result->status);
        $this->assertContains(merge_guard::ENROLMENTS_PENDING, $result->reasons);
        // The general confirmation of conflicts never closes it; only its own decision with a reason does.
        $this->assertSame(0, api::acknowledge_merge_conflicts($remove, $keep)->acknowledged);
        $this->assertContains(merge_guard::ENROLMENTS_PENDING, local\merge_service::verify($remove, $keep)->reasons);
        $record = merge_evidence::open_enrolments($remove, $keep)[0];
        $this->assertNull(api::discard_merge_enrolment((int) $record->id, $remove, $keep, self::PROOF[1])->problem);
        $this->assertSame('reconciled', local\merge_service::verify($remove, $keep)->status);
    }

    /**
     * Collision cases of activity tables beyond grades: key values of both users and the expected archive kind.
     *
     * @return array
     */
    public static function collision_provider(): array {
        return [
            'assignment grade' => ['assign_grades', ['assignment' => 7001, 'attemptnumber' => 0], 'review'],
            'assignment flags (extension)' => ['assign_user_flags', ['assignment' => 7002], 'review'],
            'H5P attempt' => ['h5pactivity_attempts', ['h5pactivityid' => 7003, 'attempt' => 1], 'review'],
            'competency' => ['competency_usercomp', ['competencyid' => 7004], 'review'],
            'forum grade' => ['forum_grades', ['forum' => 7005, 'itemnumber' => 1], 'review'],
            'survey answer' => ['survey_answers', ['survey' => 7006, 'question' => 3], 'review'],
            'workshop aggregation' => ['workshop_aggregations', ['workshopid' => 7007], 'review'],
            'policy acceptance' => ['tool_policy_acceptances', ['policyversionid' => 7008], 'review'],
            'activity completion' => ['course_modules_completion', ['coursemoduleid' => 7009], 'review'],
            'group membership' => ['groups_members', ['groupid' => 7010], 'benign'],
            'cohort membership' => ['cohort_members', ['cohortid' => 7011], 'benign'],
            'forum subscription' => ['forum_subscriptions', ['forum' => 7012], 'benign'],
        ];
    }

    /**
     * N3: every collision tool_mergeusers resolves is archived before it is resolved: work data keeps the merge
     * under review, memberships are archived as settled; without a collision the rows simply move.
     *
     * @dataProvider collision_provider
     * @param string $table Table.
     * @param array $key Collision key values.
     * @param string $kind Expected archive kind.
     */
    public function test_collisions_are_archived(string $table, array $key, string $kind): void {
        global $DB;
        $this->require_mergeusers();
        if (!$DB->get_manager()->table_exists($table)) {
            $this->markTestSkipped("$table is not installed.");
        }
        $this->assertArrayHasKey($table, local\merge_coverage::keys(), 'Known to the coverage list.');
        $keep = $this->flexaccess();
        $remove = $this->flexaccess();
        $kept = $this->row($table, $key + ['userid' => $keep]);
        $removed = $this->row($table, $key + ['userid' => $remove]);
        // A second, non-colliding row of the source (another key value) must simply move.
        $other = array_map(static fn($v) => is_int($v) ? $v + 100 : $v, $key);
        $moved = $this->row($table, $other + ['userid' => $remove]);

        $preview = merge_guard::assess($remove, $keep);
        $this->assertSame($kind === 'review', isset($preview->conflicts[$table]));
        $this->approve($remove, $keep);
        $this->assertTrue($this->mergeusers($keep, $remove));

        $archive = $DB->get_records(merge_evidence::TABLE, ['fromuserid' => $remove, 'touserid' => $keep, 'tablename' => $table]);
        $this->assertCount(1, $archive, 'One archived collision.');
        $record = reset($archive);
        $this->assertSame($kind, $record->kind);
        $this->assertSame((string) $removed, (string) json_decode($record->sourcerows)[0]->id, 'The resolved source row is kept.');
        $this->assertSame((string) $kept, (string) json_decode($record->targetrows)[0]->id);
        $this->assertSame($keep, (int) $DB->get_field($table, 'userid', ['id' => $moved]), 'The non-colliding row moved.');
        $status = merge_registry::get($remove, $keep)->status;
        $this->assertSame($kind === 'review' ? merge_registry::REVIEW : merge_registry::RECONCILED, $status);
    }

    /**
     * N3: a table merger FlexAccess cannot wrap (here a site setting of tool_mergeusers) is a coverage gap: the
     * merge is refused instead of resolving collisions without archive.
     */
    public function test_unwrapped_table_merger_refuses_the_merge(): void {
        global $DB;
        $this->require_mergeusers();
        set_config('customdbsettings', json_encode(['tablemergers' => [
            'forum_posts' => '\\tool_mergeusers\\local\\merger\\generic_table_merger',
        ]]), 'tool_mergeusers');
        $keep = $this->flexaccess();
        $remove = $this->visitor($this->clock->time() + HOURSECS);
        $this->assertArrayHasKey('forum_posts', local\merge_coverage::gaps());
        $this->assertContains(merge_policy::MERGER_COVERAGE, merge_guard::assess($remove, $keep)->blockers);
        $this->assertFalse(api::approve_merge($remove, $keep, true, ...self::PROOF));
        $this->assertFalse($this->mergeusers($keep, $remove));
        $this->assertTrue($DB->record_exists('auth_flexaccess_account', ['userid' => $remove]));
        set_config('customdbsettings', '', 'tool_mergeusers');
        $this->assertSame([], local\merge_coverage::gaps());
    }
}
