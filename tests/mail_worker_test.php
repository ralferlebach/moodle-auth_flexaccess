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
 * Tests for the FlexAccess mail worker.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace auth_flexaccess;

use auth_flexaccess\local\mail_kind;
use auth_flexaccess\local\mail_worker;

/**
 * Mail worker tests.
 *
 * @package    auth_flexaccess
 * @covers     \auth_flexaccess\local\mail_worker
 */
final class mail_worker_test extends \advanced_testcase {
    /**
     * Queue a due generic mail for a fresh user.
     *
     * @param int $now Current time.
     * @return int User id.
     */
    private function queue_due(int $now): int {
        global $DB;
        static $n = 0;
        $n++;
        $user = $this->getDataGenerator()->create_user(['email' => "worker{$n}@example.com"]);
        $DB->insert_record('auth_flexaccess_mailqueue', (object) [
            'userid' => $user->id,
            'recipient' => $user->email,
            'mailtype' => mail_kind::ACTIVATION,
            'payloadjson' => json_encode(['subject' => 'Test subject', 'body' => 'Test body']),
            'status' => 'queued',
            'attempts' => 0,
            'timecreated' => $now,
            'nextrun' => $now,
            'timesent' => null,
        ]);
        return (int) $user->id;
    }

    /**
     * process_due honours the remaining budget and issues a token per sent mail.
     */
    public function test_process_due_respects_budget(): void {
        global $DB;
        $this->resetAfterTest();
        $sink = $this->redirectEmails();
        $now = 1000000;
        for ($i = 0; $i < 3; $i++) {
            $this->queue_due($now);
        }

        $sent = mail_worker::process_due($now, 2);
        $this->assertSame(2, $sent);
        $this->assertSame(2, $sink->count());
        $this->assertEquals(2, $DB->count_records('auth_flexaccess_mailqueue', ['status' => 'sent']));
        $this->assertEquals(1, $DB->count_records('auth_flexaccess_mailqueue', ['status' => 'queued']));
        $sink->close();
    }

    /**
     * run() applies the configured rolling-hour limit.
     */
    public function test_run_applies_hourly_limit(): void {
        global $DB;
        $this->resetAfterTest();
        $sink = $this->redirectEmails();
        set_config('maillimitperhour', 10, 'auth_flexaccess');
        $now = 2000000;

        // Eight already sent within the last hour -> remaining capacity is 2.
        for ($i = 0; $i < 8; $i++) {
            $DB->insert_record('auth_flexaccess_mailqueue', (object) [
                'userid' => null, 'recipient' => "past{$i}@example.com",
                'mailtype' => mail_kind::ACTIVATION, 'payloadjson' => null,
                'status' => 'sent', 'attempts' => 1,
                'timecreated' => $now - 100, 'nextrun' => $now - 100, 'timesent' => $now - 100,
            ]);
        }
        for ($i = 0; $i < 3; $i++) {
            $this->queue_due($now);
        }

        $sent = mail_worker::run($now);
        $this->assertSame(2, $sent);
        $this->assertSame(2, $sink->count());
        $sink->close();
    }

    /**
     * prune_delivered removes old finished jobs but keeps queued ones.
     *
     * @return void
     */
    public function test_prune_delivered(): void {
        global $DB;
        $this->resetAfterTest();
        $now = 100 * DAYSECS;
        $mk = function (string $status, int $created) use ($DB, $now): void {
            $DB->insert_record('auth_flexaccess_mailqueue', (object) [
                'userid' => 0, 'recipient' => 'x@example.com', 'mailtype' => mail_kind::ACTIVATION,
                'payloadjson' => json_encode(['subject' => 's', 'body' => 'b']),
                'status' => $status, 'attempts' => 0, 'timecreated' => $created,
                'nextrun' => $created, 'timesent' => null,
            ]);
        };
        $mk('sent', $now - 40 * DAYSECS);
        $mk('failed', $now - 40 * DAYSECS);
        $mk('sent', $now - 1 * DAYSECS);
        $mk('queued', $now - 40 * DAYSECS);

        mail_worker::prune_delivered($now - 30 * DAYSECS);

        // Old sent+failed gone; recent sent and any queued remain.
        $this->assertSame(0, $DB->count_records_select(
            'auth_flexaccess_mailqueue',
            "status IN ('sent','failed') AND timecreated < :c",
            ['c' => $now - 30 * DAYSECS]
        ));
        $this->assertSame(1, $DB->count_records('auth_flexaccess_mailqueue', ['status' => 'queued']));
        $this->assertSame(1, $DB->count_records('auth_flexaccess_mailqueue', ['status' => 'sent']));
    }
}
