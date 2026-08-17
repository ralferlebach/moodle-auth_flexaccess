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

/** Mail worker tests. */
final class mail_worker_test extends \advanced_testcase {
    /**
     * Queue a due persistence follow-up for a fresh user.
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
            'mailtype' => mail_kind::PERSISTENCE_FOLLOWUP,
            'payloadjson' => json_encode(['kind' => mail_kind::PERSISTENCE_FOLLOWUP]),
            'status' => 'queued',
            'attempts' => 0,
            'timecreated' => $now,
            'nextrun' => $now,
            'timesent' => null,
        ]);
        return (int) $user->id;
    }

    /** process_due honours the remaining budget and issues a token per sent mail. */
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
        // A token was issued for each sent mail, just before sending.
        $this->assertEquals(2, $DB->count_records('auth_flexaccess_token', ['purpose' => 'persistence']));
        $sink->close();
    }

    /** run() applies the configured rolling-hour limit. */
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
                'mailtype' => mail_kind::PERSISTENCE_FOLLOWUP, 'payloadjson' => null,
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
}
