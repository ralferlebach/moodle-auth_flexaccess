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

use enrol_flexaccess\local\participant_role;

/**
 * Tests for the external identity merge reconciliation (issue auth#4).
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \auth_flexaccess\local\merge_service
 * @covers \auth_flexaccess\observer
 */
final class merge_service_test extends \advanced_testcase {
    /** @var \stdClass Course. */
    private $course;
    /** @var \stdClass FlexAccess enrol instance. */
    private $instance;

    /**
     * Course with an enabled FlexAccess instance.
     *
     * @return void
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        if (!class_exists('\enrol_flexaccess\api') || !method_exists('\enrol_flexaccess\api', 'transfer_user_enrolments')) {
            $this->markTestSkipped('Requires the enrol_flexaccess sibling plugin (1.1.0+).');
        }
        $this->resetAfterTest();
        set_config('enrol_plugins_enabled', 'manual,flexaccess');
        $this->course = $this->getDataGenerator()->create_course();
        $enrolid = \enrol_flexaccess\local\enrol_service::ensure_instance((int) $this->course->id);
        $this->instance = $DB->get_record('enrol', ['id' => $enrolid]);
    }

    /**
     * A temporary FlexAccess visitor enrolled in the course (restricted, with end time).
     *
     * @param int $timeend Enrolment end.
     * @param int $status Enrolment status.
     * @return int User id.
     */
    private function visitor(int $timeend, int $status = ENROL_USER_ACTIVE): int {
        $userid = api::create_temporary_user(time() + 3600, (int) $this->course->id);
        enrol_get_plugin('flexaccess')->enrol_user($this->instance, $userid, participant_role::get_id(), time(), $timeend, $status);
        participant_role::restrict($userid);
        return $userid;
    }

    /**
     * The FlexAccess enrolment of a user in the course, or null.
     *
     * @param int $userid User id.
     * @return \stdClass|null
     */
    private function ue(int $userid): ?\stdClass {
        global $DB;
        return $DB->get_record('user_enrolments', ['enrolid' => $this->instance->id, 'userid' => $userid]) ?: null;
    }

    /**
     * Whether the user holds the restriction role.
     *
     * @param int $userid User id.
     * @return bool
     */
    private function restricted(int $userid): bool {
        return user_has_role_assignment($userid, participant_role::get_restriction_id(), \context_system::instance()->id);
    }

    /**
     * Case 1 (+5): temporary visitor merged into a durable LTI identity; restriction lifted, source cleaned.
     *
     * @return void
     */
    public function test_temporary_into_durable_identity(): void {
        global $DB;
        $source = $this->visitor(time() + 3600);
        $target = (int) $this->getDataGenerator()->create_user(['auth' => 'lti'])->id;
        participant_role::restrict($target); // Inherited by a direct table merge.
        $this->assertTrue($this->restricted($source));

        $result = api::reconcile_external_identity_merge($source, $target);
        $this->assertSame('reconciled', $result->status);
        $this->assertSame(1, $result->transferred);
        $this->assertNotNull($this->ue($target));
        $this->assertNull($this->ue($source));
        $this->assertFalse($this->restricted($target));
        $this->assertFalse($this->restricted($source));
        $this->assertFalse($DB->record_exists('auth_flexaccess_account', ['userid' => $source]));
        // The survivor has the course role, i.e. it does not fall back to guest rights.
        $context = \context_course::instance((int) $this->course->id);
        $this->assertTrue(user_has_role_assignment($target, participant_role::get_id(), $context->id));
        $this->assertTrue(is_enrolled($context, $target, '', true));
    }

    /**
     * Case 2: source and target in the same course - merged, active and later end win.
     *
     * @return void
     */
    public function test_both_in_same_course(): void {
        $end = time() + 7200;
        $source = $this->visitor($end);
        $target = (int) $this->getDataGenerator()->create_user()->id;
        $plugin = enrol_get_plugin('flexaccess');
        $plugin->enrol_user($this->instance, $target, participant_role::get_id(), time(), time() + 60, ENROL_USER_SUSPENDED);
        $result = api::reconcile_external_identity_merge($source, $target);
        $this->assertSame(1, $result->merged);
        $ue = $this->ue($target);
        $this->assertEquals(ENROL_USER_ACTIVE, $ue->status);
        $this->assertEquals($end, $ue->timeend);
    }

    /**
     * Case 3: only the source is enrolled - the enrolment moves with its end time (account permanence
     * is not course permanence).
     *
     * @return void
     */
    public function test_only_source_enrolled_keeps_course_end(): void {
        $end = time() + 3600;
        $source = $this->visitor($end);
        $target = (int) $this->getDataGenerator()->create_user()->id;
        api::reconcile_external_identity_merge($source, $target);
        $this->assertEquals($end, $this->ue($target)->timeend);
    }

    /**
     * Case 4: the target already has another durable enrolment; it is left untouched.
     *
     * @return void
     */
    public function test_other_durable_enrolment_untouched(): void {
        global $DB;
        $source = $this->visitor(time() + 3600);
        $target = (int) $this->getDataGenerator()->create_user()->id;
        $this->getDataGenerator()->enrol_user($target, (int) $this->course->id, 'student', 'manual');
        $manual = $DB->get_record_sql(
            "SELECT ue.* FROM {user_enrolments} ue JOIN {enrol} e ON e.id = ue.enrolid
              WHERE e.enrol = 'manual' AND e.courseid = :c AND ue.userid = :u",
            ['c' => $this->course->id, 'u' => $target]
        );
        api::reconcile_external_identity_merge($source, $target);
        $after = $DB->get_record('user_enrolments', ['id' => $manual->id]);
        $this->assertEquals($manual->timeend, $after->timeend);
        $this->assertEquals($manual->status, $after->status);
    }

    /**
     * Case 6: processing the merge twice creates no second enrolment or role.
     *
     * @return void
     */
    public function test_idempotent(): void {
        global $DB;
        $source = $this->visitor(time() + 3600);
        $target = (int) $this->getDataGenerator()->create_user()->id;
        api::reconcile_external_identity_merge($source, $target);
        $second = api::reconcile_external_identity_merge($source, $target);
        $this->assertSame('nothingtodo', $second->status);
        $this->assertSame(1, $DB->count_records('user_enrolments', ['enrolid' => $this->instance->id, 'userid' => $target]));
        $context = \context_course::instance((int) $this->course->id);
        $this->assertSame(1, $DB->count_records('role_assignments', [
            'userid' => $target, 'contextid' => $context->id, 'roleid' => participant_role::get_id(),
        ]));
    }

    /**
     * Case 7: an expired temporary enrolment vs. an LTI merge that establishes permanent course access.
     *
     * @return void
     */
    public function test_permanent_course_access_is_explicit(): void {
        $expired = time() - 10;
        $a = $this->visitor($expired, ENROL_USER_SUSPENDED);
        $durable = (int) $this->getDataGenerator()->create_user(['auth' => 'lti'])->id;
        // Default: identity permanence only - the course end carries over and may still expire.
        api::reconcile_external_identity_merge($a, $durable, false);
        $this->assertEquals($expired, $this->ue($durable)->timeend);

        // Explicit permanent course access: the temporary FlexAccess limit no longer cuts access.
        $b = $this->visitor($expired, ENROL_USER_SUSPENDED);
        $other = (int) $this->getDataGenerator()->create_user(['auth' => 'lti'])->id;
        api::reconcile_external_identity_merge($b, $other, true);
        $this->assertEquals(0, $this->ue($other)->timeend);
        $this->assertEquals(ENROL_USER_ACTIVE, $this->ue($other)->status);
        \enrol_flexaccess\local\enrol_expiry::process(time());
        $this->assertEquals(0, $this->ue($other)->timeend);
    }

    /**
     * Invalid pairs are rejected without side effects; tool_mergeusers events are reconciled.
     *
     * @return void
     */
    public function test_invalid_and_observer(): void {
        $source = $this->visitor(time() + 3600);
        $this->assertSame('invalid', api::reconcile_external_identity_merge($source, $source)->status);
        $this->assertSame('invalid', api::reconcile_external_identity_merge($source, 999999)->status);
        $this->assertNotNull($this->ue($source));

        $target = (int) $this->getDataGenerator()->create_user()->id;
        $event = \core\event\user_updated::create([
            'objectid' => $target,
            'relateduserid' => $target,
            'context' => \context_user::instance($target),
            'other' => ['usersinvolved' => ['toid' => $target, 'fromid' => $source]],
        ]);
        observer::user_merged($event);
        $this->assertNotNull($this->ue($target));
    }
}
