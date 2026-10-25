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
 * Merge evidence: data minimisation, decisions per recorded enrolment, bounds and query volume (auth #13).
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace auth_flexaccess;

use auth_flexaccess\local\account_service;
use auth_flexaccess\local\merge_evidence;
use auth_flexaccess\local\merge_guard;
use auth_flexaccess\local\merge_registry;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\writer;

/**
 * Real merges through the installed tool_mergeusers where a merge is the subject; direct calls where the
 * archive itself is. Every scenario reads one frozen Moodle clock.
 *
 * @package    auth_flexaccess
 * @covers \auth_flexaccess\local\merge_evidence
 * @covers \auth_flexaccess\privacy\provider
 * @covers \auth_flexaccess\observer
 * @covers \auth_flexaccess\task\purge_merge_evidence
 * @covers \auth_flexaccess\api
 */
final class merge_evidence_test extends \advanced_testcase {
    /** An accepted ownership proof (kind, evidence). */
    private const PROOF = ['documents', 'Student record 4711 and photo ID checked at the help desk.'];

    /** @var \frozen_clock The scenario's clock. */
    private \frozen_clock $clock;

    /** @var \stdClass Course with FlexAccess. */
    private \stdClass $course;

    /** @var \stdClass Second course with FlexAccess. */
    private \stdClass $other;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->clock = $this->mock_clock_with_frozen();
        $this->setAdminUser();
        set_config('auth', 'flexaccess,oauth2');
        set_config('enrol_plugins_enabled', 'manual,flexaccess');
        $this->course = $this->getDataGenerator()->create_course();
        $this->other = $this->getDataGenerator()->create_course();
        foreach ([$this->course, $this->other] as $course) {
            \enrol_flexaccess\local\enrol_service::ensure_instance((int) $course->id);
        }
        merge_evidence::reset_cache();
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
     * A durable FlexAccess account with password, enrolled through FlexAccess in both courses.
     *
     * @return int
     */
    private function flexaccess(): int {
        $user = $this->getDataGenerator()->create_user(['auth' => 'flexaccess']);
        update_internal_user_password($user, 'Flex-Pass!1');
        account_service::create_authenticated((int) $user->id, account_service::generate_unique_reference());
        foreach ([$this->course, $this->other] as $course) {
            $this->assertTrue(\enrol_flexaccess\local\enrol_service::admin_enrol((int) $course->id, (int) $user->id));
        }
        return (int) $user->id;
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

    /**
     * Everything FlexAccess stored about a pair, as one string.
     *
     * @param int $from Source.
     * @param int $to Survivor.
     * @return string
     */
    private function archive_dump(int $from, int $to): string {
        global $DB;
        return json_encode(array_values($DB->get_records(merge_evidence::TABLE, ['fromuserid' => $from, 'touserid' => $to])));
    }

    /**
     * The merge evidence section of a user's privacy export, as one string.
     *
     * @param int $userid User.
     * @return string
     */
    private function privacy_dump(int $userid): string {
        $user = \core_user::get_user($userid);
        $context = \context_user::instance($userid);
        privacy\provider::export_user_data(new approved_contextlist($user, 'auth_flexaccess', [$context->id]));
        $data = writer::with_context($context)->get_data([
            get_string('pluginname', 'auth_flexaccess'),
            get_string('privacy:metadata:mergeevid', 'auth_flexaccess'),
        ]);
        return json_encode($data);
    }

    /**
     * P0: an OAuth 2 refresh-token collision is archived and diagnosable (issuer, times, which side kept), but
     * neither token nor scope hash is stored, shown or exported.
     */
    public function test_refresh_token_collision_keeps_no_secret(): void {
        global $DB;
        $this->require_mergeusers();
        $issuer = new \core\oauth2\issuer(0, (object) [
            'name' => 'Test IdP', 'baseurl' => 'https://idp.example.org', 'clientid' => 'c', 'clientsecret' => 's',
            'enabled' => 1, 'showonloginpage' => \core\oauth2\issuer::EVERYWHERE, 'image' => '',
            'loginscopes' => 'openid', 'loginscopesoffline' => 'openid', 'loginparams' => '', 'loginparamsoffline' => '',
            'alloweddomains' => '',
        ]);
        $issuer->create();
        $keep = $this->flexaccess();
        $remove = $this->flexaccess();
        $scopehash = sha1('openid profile email');
        foreach ([$keep => 'REFRESH-SECRET-KEEP-7f3a', $remove => 'REFRESH-SECRET-REMOVE-91c2'] as $userid => $token) {
            $this->row('oauth2_refresh_token', [
                'issuerid' => $issuer->get('id'),
                'userid' => $userid,
                'token' => $token,
                'scopehash' => $scopehash,
                'timecreated' => $this->clock->time(),
                'timemodified' => $this->clock->time(),
                'usermodified' => get_admin()->id,
            ]);
        }
        $this->assertTrue(api::approve_merge($remove, $keep, true, ...self::PROOF));
        $this->assertTrue($this->mergeusers($keep, $remove));

        $record = $DB->get_record(merge_evidence::TABLE, [
            'fromuserid' => $remove, 'touserid' => $keep, 'tablename' => 'oauth2_refresh_token',
        ], '*', MUST_EXIST);
        $this->assertSame('benign', $record->kind);
        $source = merge_evidence::rows($record, 'sourcerows')[0];
        $this->assertEquals($issuer->get('id'), $source->issuerid, 'Diagnosable: which issuer collided.');
        $this->assertContains('token', $source->_omitted);
        $this->assertStringStartsWith('sha256:', $source->scopehash);
        foreach ([$this->archive_dump($remove, $keep), $this->privacy_dump($keep), $this->privacy_dump($remove)] as $dump) {
            $this->assertStringNotContainsString('REFRESH-SECRET', $dump);
            $this->assertStringNotContainsString($scopehash, $dump);
        }
        $this->assertSame(merge_registry::RECONCILED, merge_registry::get($remove, $keep)->status);
    }

    /**
     * P0: a device collision keeps platform and times, but no push id, device UUID or device name.
     */
    public function test_device_collision_keeps_no_push_credential(): void {
        global $DB;
        $this->require_mergeusers();
        $keep = $this->flexaccess();
        $remove = $this->flexaccess();
        foreach ([$keep, $remove] as $userid) {
            $this->row('user_devices', [
                'userid' => $userid,
                'appid' => 'com.moodle.moodlemobile',
                'name' => 'Phone of Alice Example',
                'model' => 'Pixel 9',
                'platform' => 'android',
                'version' => '15',
                'pushid' => 'PUSH-SECRET-0d9e8f',
                'uuid' => 'UUID-SECRET-4411',
                'timecreated' => $this->clock->time(),
                'timemodified' => $this->clock->time(),
            ]);
        }
        $this->assertTrue(api::approve_merge($remove, $keep, true, ...self::PROOF));
        $this->assertTrue($this->mergeusers($keep, $remove));
        $record = $DB->get_record(merge_evidence::TABLE, [
            'fromuserid' => $remove, 'touserid' => $keep, 'tablename' => 'user_devices',
        ], '*', MUST_EXIST);
        $source = merge_evidence::rows($record, 'sourcerows')[0];
        $this->assertSame('android', $source->platform);
        $this->assertEqualsCanonicalizing(['name', 'model', 'version', 'uuid'], array_intersect(
            ['name', 'model', 'version', 'uuid'],
            $source->_omitted
        ));
        foreach ([$this->archive_dump($remove, $keep), $this->privacy_dump($keep)] as $dump) {
            $this->assertStringNotContainsString('PUSH-SECRET', $dump);
            $this->assertStringNotContainsString('UUID-SECRET', $dump);
            $this->assertStringNotContainsString('Alice', $dump);
        }
    }

    /**
     * P0: the positive list - ids, user and key columns, numeric columns and listed text columns; never a
     * column whose name marks a credential, never an unlisted text column.
     */
    public function test_positive_column_list(): void {
        [$keep, $omit] = merge_evidence::columns('grade_grades', ['userid'], ['itemid']);
        $this->assertContains('finalgrade', $keep);
        $this->assertContains('feedback', $keep, 'Listed text column (needed to review a grade).');
        [$keep, $omit] = merge_evidence::columns('tool_policy_acceptances', ['userid'], ['policyversionid']);
        $this->assertContains('lang', $keep);
        $this->assertContains('note', $omit, 'Unlisted text column.');
        [$keep, $omit] = merge_evidence::columns('oauth2_refresh_token', ['userid'], ['issuerid', 'scopehash']);
        $this->assertContains('scopehash', $keep, 'Key column (stored as fingerprint only).');
        $this->assertContains('token', $omit);
        foreach (['token', 'refreshtoken', 'secret', 'password', 'passwordhash', 'pushid', 'uuid', 'sid', 'apikey'] as $name) {
            $this->assertTrue(merge_evidence::is_secret($name), $name);
        }
        foreach (['finalgrade', 'status', 'timemodified', 'userid', 'attempt'] as $name) {
            $this->assertFalse(merge_evidence::is_secret($name), $name);
        }
    }

    /**
     * P0 migration: rows archived by earlier versions are reduced; grade evidence survives; an archive of an
     * uninstalled table keeps numeric values only; a second run changes nothing.
     */
    public function test_legacy_archive_is_sanitised(): void {
        global $DB;
        $keep = $this->flexaccess();
        $remove = $this->flexaccess();
        $insert = fn(string $table, string $kind, string $keyfields, string $keyvalues, array $source) =>
            $DB->insert_record(merge_evidence::TABLE, (object) [
                'fromuserid' => $remove, 'touserid' => $keep, 'tablename' => $table, 'kind' => $kind,
                'keyfields' => $keyfields, 'keyvalues' => $keyvalues, 'conflictkey' => 0,
                'sourcerows' => json_encode([$source]), 'targetrows' => json_encode([$source]),
                'timeacknowledged' => 0, 'timecreated' => $this->clock->time(),
            ]);
        $grade = $insert('grade_grades', 'review', 'itemid', '["42"]', [
            'id' => 7, 'itemid' => 42, 'userid' => $remove, 'finalgrade' => '55.00000', 'feedback' => 'Gut gemacht',
            'secrettoken' => 'LEGACY-SECRET-1',
        ]);
        $token = $insert('oauth2_refresh_token', 'benign', 'issuerid,scopehash', '["3","LEGACY-SCOPEHASH-2"]', [
            'id' => 8, 'issuerid' => 3, 'userid' => $remove, 'token' => 'LEGACY-SECRET-3', 'scopehash' => 'LEGACY-SCOPEHASH-2',
        ]);
        $gone = $insert('local_uninstalled_thing', 'review', 'thingid', '["5"]', [
            'id' => 9, 'thingid' => 5, 'userid' => $remove, 'score' => 12, 'comment' => 'LEGACY-TEXT-4',
            'apikey' => 'LEGACY-SECRET-5',
        ]);

        $changed = merge_evidence::sanitise_archive();
        $this->assertSame(['grade_grades' => 1, 'oauth2_refresh_token' => 1, 'local_uninstalled_thing' => 1], $changed);
        $dump = $this->archive_dump($remove, $keep);
        foreach (['LEGACY-SECRET', 'LEGACY-SCOPEHASH', 'LEGACY-TEXT'] as $marker) {
            $this->assertStringNotContainsString($marker, $dump);
        }
        $graderow = merge_evidence::rows($DB->get_record(merge_evidence::TABLE, ['id' => $grade]), 'sourcerows')[0];
        $this->assertSame('55.00000', $graderow->finalgrade, 'Grade evidence kept.');
        $this->assertSame('Gut gemacht', $graderow->feedback);
        $gonerow = merge_evidence::rows($DB->get_record(merge_evidence::TABLE, ['id' => $gone]), 'sourcerows')[0];
        $this->assertSame(12, $gonerow->score);
        $keyvalues = json_decode($DB->get_field(merge_evidence::TABLE, 'keyvalues', ['id' => $token]));
        $this->assertSame('3', $keyvalues[0]);
        $this->assertStringStartsWith('sha256:', $keyvalues[1]);
        $this->assertSame([], merge_evidence::sanitise_archive(), 'Idempotent.');
    }

    /**
     * Set up a merge whose recorded FlexAccess enrolments (both courses) could not be merged: the survivor's
     * enrolments are gone, the source's rows were removed by the merge.
     *
     * @return array [source, survivor, enrol id of course 1, enrol id of course 2]
     */
    private function pending_pair(): array {
        global $DB;
        $keep = $this->flexaccess();
        $remove = $this->flexaccess();
        $plugin = enrol_get_plugin('flexaccess');
        $ids = [];
        $this->assertSame(2, merge_evidence::capture_enrolments($remove, $keep));
        foreach ([$this->course, $this->other] as $course) {
            $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'flexaccess'], '*', MUST_EXIST);
            $DB->delete_records('user_enrolments', ['enrolid' => $instance->id, 'userid' => $remove]);
            $plugin->unenrol_user($instance, $keep);
            $ids[] = (int) $instance->id;
        }
        $this->assertTrue(api::approve_merge($remove, $keep, true, ...self::PROOF));
        local\merge_service::reconcile($remove, $keep, false, null, 'admin');
        $this->assertEqualsCanonicalizing($ids, merge_evidence::pending_enrolments($remove, $keep));
        return [$remove, $keep, $ids[0], $ids[1]];
    }

    /**
     * P1: the general confirmation closes review conflicts only; a recorded enrolment that could not be merged
     * stays open and the merge stays under review.
     */
    public function test_general_confirmation_leaves_failed_enrolments_open(): void {
        global $DB;
        [$remove, $keep] = $this->pending_pair();
        $DB->insert_record(merge_evidence::TABLE, (object) [
            'fromuserid' => $remove, 'touserid' => $keep, 'tablename' => 'grade_grades', 'kind' => 'review',
            'keyfields' => 'itemid', 'keyvalues' => '["1"]', 'conflictkey' => 1, 'sourcerows' => '[]', 'targetrows' => '[]',
            'timeacknowledged' => 0, 'timecreated' => $this->clock->time(),
        ]);
        $result = api::acknowledge_merge_conflicts($remove, $keep);
        $this->assertSame(1, $result->acknowledged, 'Only the review conflict.');
        $this->assertSame(merge_registry::REVIEW, $result->status);
        $this->assertContains(merge_guard::ENROLMENTS_PENDING, $result->reasons);
        $this->assertNotContains(merge_guard::CONFLICTS_RECORDED, $result->reasons);
        $this->assertCount(2, merge_evidence::open_enrolments($remove, $keep));
        $this->assertSame(
            merge_evidence::DECISION_REVIEWED,
            $DB->get_field(merge_evidence::TABLE, 'decision', ['fromuserid' => $remove, 'kind' => 'review'])
        );
    }

    /**
     * P1: discarding needs a reason and closes exactly that enrolment, with decision, administrator, time and
     * reason recorded and the archived values kept; the other one stays open until its own state is fixed and
     * a re-check merges it (idempotent).
     */
    public function test_discarding_one_enrolment_records_the_decision(): void {
        global $DB;
        [$remove, $keep, $first, $second] = $this->pending_pair();
        $records = [];
        foreach (merge_evidence::open_enrolments($remove, $keep) as $record) {
            $records[(int) $record->conflictkey] = $record;
        }
        $short = api::discard_merge_enrolment((int) $records[$first]->id, $remove, $keep, 'too short');
        $this->assertSame('reason', $short->problem);
        $onlyaddress = api::discard_merge_enrolment((int) $records[$first]->id, $remove, $keep, 'someone@example.org x');
        $this->assertSame('reason', $onlyaddress->problem);
        $this->assertSame('notfound', api::discard_merge_enrolment((int) $records[$first]->id, $keep, $remove, self::PROOF[1])
            ->problem, 'Bound to its pair.');
        $student = $this->getDataGenerator()->create_user();
        $this->setUser($student);
        $denied = api::discard_merge_enrolment((int) $records[$first]->id, $remove, $keep, self::PROOF[1]);
        $this->assertSame('nopermission', $denied->problem);
        $this->setAdminUser();
        $this->assertCount(2, merge_evidence::open_enrolments($remove, $keep), 'Nothing changed so far.');

        $this->clock->bump(60);
        $reason = 'Course was closed; the person agreed in writing not to keep this access.';
        $result = api::discard_merge_enrolment((int) $records[$first]->id, $remove, $keep, $reason);
        $this->assertNull($result->problem);
        $decided = $DB->get_record(merge_evidence::TABLE, ['id' => $records[$first]->id]);
        $this->assertSame(merge_evidence::DECISION_DISCARDED, $decided->decision);
        $this->assertEquals(get_admin()->id, $decided->acknowledgedby);
        $this->assertEquals($this->clock->time(), $decided->timeacknowledged);
        $this->assertSame($reason, $decided->decisionreason);
        $this->assertSame($records[$first]->sourcerows, $decided->sourcerows, 'Evidence kept.');
        $this->assertSame('notpending', api::discard_merge_enrolment((int) $records[$first]->id, $remove, $keep, $reason)
            ->problem, 'Decided once.');
        // The other enrolment is untouched and keeps the merge under review.
        $this->assertSame([$second], merge_evidence::pending_enrolments($remove, $keep));
        $this->assertContains(merge_guard::ENROLMENTS_PENDING, $result->reasons);

        // Its own state is fixed: a re-check merges it, once.
        $instance = $DB->get_record('enrol', ['id' => $second], '*', MUST_EXIST);
        enrol_get_plugin('flexaccess')->enrol_user($instance, $keep);
        $this->assertSame('reconciled', api::recheck_merge($remove, $keep)->status);
        $this->assertSame(merge_evidence::DECISION_MERGED, $DB->get_field(merge_evidence::TABLE, 'decision', [
            'id' => $records[$second]->id,
        ]));
        $this->assertSame('reconciled', api::recheck_merge($remove, $keep)->status);
        $this->assertSame(1, $DB->count_records('user_enrolments', ['enrolid' => $second, 'userid' => $keep]));
        $this->assertFalse($DB->record_exists('user_enrolments', ['enrolid' => $first, 'userid' => $keep]), 'Discarded.');
    }

    /**
     * P2: collisions beyond the row bound refuse the merge (rolled back, refusal recorded with its reason, no
     * archive); with a higher bound the same merge succeeds.
     */
    public function test_evidence_bound_refuses_and_rolls_back(): void {
        global $DB;
        $this->require_mergeusers();
        $keep = $this->flexaccess();
        $remove = $this->flexaccess();
        foreach ([1, 2] as $n) {
            $item = $this->getDataGenerator()->create_grade_item(['courseid' => $this->course->id]);
            foreach ([$keep, $remove] as $userid) {
                $DB->insert_record('grade_grades', (object) [
                    'itemid' => $item->id, 'userid' => $userid, 'finalgrade' => 10 * $n, 'rawgrade' => 10 * $n,
                ]);
            }
        }
        set_config('mergeevidencemaxrows', 3, 'auth_flexaccess');
        $this->assertTrue(api::approve_merge($remove, $keep, true, ...self::PROOF));
        $this->assertFalse($this->mergeusers($keep, $remove));
        $row = merge_registry::get($remove, $keep);
        $this->assertSame(merge_registry::REFUSED, $row->status);
        $this->assertContains(merge_evidence::REFUSED_LIMIT, explode(',', $row->reasons));
        $this->assertSame(0, $DB->count_records(merge_evidence::TABLE, ['fromuserid' => $remove]));
        $this->assertSame(2, $DB->count_records('grade_grades', ['userid' => $remove]), 'Rolled back.');
        $this->assertTrue($DB->record_exists('auth_flexaccess_account', ['userid' => $remove]));

        set_config('mergeevidencemaxrows', 100, 'auth_flexaccess');
        $this->assertTrue(api::approve_merge($remove, $keep, true, ...self::PROOF));
        [$success, $errors] = (new \tool_mergeusers\local\user_merger())->merge($keep, $remove);
        $this->assertTrue((bool) $success, json_encode($errors));
        $this->assertSame(2, $DB->count_records(merge_evidence::TABLE, ['fromuserid' => $remove, 'tablename' => 'grade_grades']));
    }

    /**
     * Capture the grade collisions of n grade items and count the database statements.
     *
     * @param int $n Number of colliding grade items.
     * @return array{reads: int, writes: int, peak: int, records: int}
     */
    private function capture_grades(int $n): array {
        global $DB;
        $keep = $this->flexaccess();
        $remove = $this->flexaccess();
        $courseid = (int) $this->course->id;
        $items = [];
        for ($i = 0; $i < $n; $i++) {
            $items[] = (int) $DB->insert_record('grade_items', (object) [
                'courseid' => $courseid, 'itemtype' => 'manual', 'itemname' => "Item $i", 'grademax' => 100,
                'timecreated' => $this->clock->time(), 'timemodified' => $this->clock->time(),
            ]);
        }
        $grades = [];
        foreach ($items as $itemid) {
            foreach ([$keep, $remove] as $userid) {
                $grades[] = (object) ['itemid' => $itemid, 'userid' => $userid, 'finalgrade' => 50, 'rawgrade' => 50];
            }
        }
        $DB->insert_records('grade_grades', $grades);
        merge_evidence::reset_cache();
        $reads = $DB->perf_get_reads();
        $writes = $DB->perf_get_writes();
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }
        $memory = memory_get_usage();
        $review = merge_evidence::capture_table('grade_grades', ['userid'], ['itemid'], $remove, $keep);
        return [
            'reads' => $DB->perf_get_reads() - $reads,
            'writes' => $DB->perf_get_writes() - $writes,
            'peak' => memory_get_peak_usage() - $memory,
            'records' => $review,
        ];
    }

    /**
     * P2: reading the collisions takes a constant number of queries (no query per key value); archiving takes
     * bulk inserts. Measured for 25 and 500 colliding grade items on the test database.
     */
    public function test_collision_capture_query_volume_is_bounded(): void {
        $small = $this->capture_grades(25);
        $large = $this->capture_grades(500);
        $this->assertSame(25, $small['records']);
        $this->assertSame(500, $large['records']);
        // Record sets may fetch in chunks (PostgreSQL cursors): a few more reads, never one per collision.
        $this->assertLessThanOrEqual($small['reads'] + 3, $large['reads'], 'Reads do not grow with the collisions.');
        $this->assertLessThanOrEqual(15, $large['reads']);
        $this->assertLessThanOrEqual(20, $large['writes'], 'Bulk inserts, not one per collision.');
        if ($file = getenv('FLEXACCESS_MEASURE')) {
            global $DB;
            file_put_contents($file, json_encode([
                'db' => $DB->get_dbfamily(),
                'small' => $small,
                'large' => $large,
            ]) . "\n", FILE_APPEND);
        }
    }

    /**
     * Retention: settled evidence of reconciled merges is deleted after the retention period; open records and
     * merges under review stay.
     */
    public function test_settled_evidence_is_purged_after_retention(): void {
        global $DB;
        [$remove, $keep] = $this->pending_pair();
        $settled = $DB->insert_record(merge_evidence::TABLE, (object) [
            'fromuserid' => $remove, 'touserid' => $keep, 'tablename' => 'groups_members', 'kind' => 'benign',
            'keyfields' => 'groupid', 'keyvalues' => '["1"]', 'conflictkey' => 1, 'sourcerows' => '[]', 'targetrows' => '[]',
            'timeacknowledged' => $this->clock->time(), 'timecreated' => $this->clock->time(),
        ]);
        $this->clock->bump(400 * DAYSECS);
        $this->assertSame(0, merge_evidence::purge_settled(365), 'The merge is under review: everything stays.');
        $this->assertTrue(api::approve_merge($remove, $keep, true, ...self::PROOF), 'The old approval has expired.');
        foreach (merge_evidence::open_enrolments($remove, $keep) as $record) {
            api::discard_merge_enrolment((int) $record->id, $remove, $keep, 'Access no longer needed, confirmed by the person.');
        }
        $this->assertSame(merge_registry::RECONCILED, merge_registry::get($remove, $keep)->status);
        $this->assertSame(1, merge_evidence::purge_settled(365), 'Only the old settled record.');
        $this->assertFalse($DB->record_exists(merge_evidence::TABLE, ['id' => $settled]));
        $this->assertSame(2, $DB->count_records(merge_evidence::TABLE, ['fromuserid' => $remove]), 'Recent decisions stay.');
        $this->assertSame(0, merge_evidence::purge_settled(0), '0 keeps everything.');
        $this->assertSame(365, task\purge_merge_evidence::retention_days());
    }
}
