<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace auth_flexaccess;

use auth_flexaccess\local\mail_worker;

/**
 * The hourly send limit must hold even when a delivered mail is not acknowledged.
 *
 * A mail is handed to SMTP before the owning component is told about it. If the acknowledgement
 * fails the row leaves the 'sent' state while the mail is already out, so counting by status let
 * delivered mail vanish from the hourly balance.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \auth_flexaccess\local\mail_worker
 */
final class mail_throttle_test extends \advanced_testcase {
    /**
     * Insert a queue row in a given state.
     *
     * @param string $status Queue status.
     * @param int $timesent Delivery timestamp, 0 for none.
     * @param int $now Current time.
     * @return void
     */
    private function row(string $status, int $timesent, int $now): void {
        global $DB;
        $DB->insert_record('auth_flexaccess_mailqueue', (object) [
            'userid' => null,
            'recipient' => 'someone@example.com',
            'mailtype' => 'test',
            'payloadjson' => json_encode(['subject' => 's', 'body' => 'b', 'bodyhtml' => 'b']),
            'status' => $status,
            'attempts' => 0,
            'timecreated' => $now - 60,
            'nextrun' => $now - 60,
            'timesent' => $timesent ?: null,
        ]);
    }

    public function test_delivered_but_unacknowledged_mail_counts_against_the_limit(): void {
        $this->resetAfterTest();
        $now = time();

        $this->row('sent', $now - 10, $now);
        $this->row('ackpending', $now - 10, $now);
        $this->row('ackfailed', $now - 10, $now);
        // Never delivered, so it must not count.
        $this->row('queued', 0, $now);
        // Delivered, but longer ago than the window.
        $this->row('sent', $now - 2 * HOURSECS, $now);

        $this->assertSame(
            3,
            mail_worker::count_sent_last_hour($now),
            'A delivered mail disappeared from the hourly balance because its acknowledgement failed.'
        );
    }

    public function test_acknowledgements_run_even_when_the_send_budget_is_exhausted(): void {
        global $DB;
        $this->resetAfterTest();
        $now = time();
        require_once(__DIR__ . '/../../../admin/tool/flexaccess/tests/fixtures/failing_ack_renderer.php');

        // No send capacity left at all.
        set_config('maillimitperhour', 10, 'auth_flexaccess');
        for ($i = 0; $i < 10; $i++) {
            $this->row('sent', $now - 10, $now);
        }
        // One acknowledgement outstanding; it needs no SMTP capacity.
        $DB->insert_record('auth_flexaccess_mailqueue', (object) [
            'userid' => null,
            'recipient' => 'ack@example.com',
            'mailtype' => 'test',
            'payloadjson' => json_encode([
                'kind' => 'deferred',
                'renderer' => \tool_flexaccess\tests\fixtures\failing_ack_renderer::class,
                'context' => [],
            ]),
            'status' => 'ackpending',
            'attempts' => 0,
            'timecreated' => $now - 60,
            'nextrun' => $now - 60,
            'timesent' => $now - 10,
        ]);

        $sink = $this->redirectEmails();
        mail_worker::run($now);
        $messages = $sink->get_messages();
        $sink->close();

        // The acknowledgement was attempted without sending anything.
        $this->assertCount(0, $messages);
        $this->assertSame(
            0,
            $DB->count_records('auth_flexaccess_mailqueue', ['status' => 'ackpending', 'attempts' => 0]),
            'The pending acknowledgement was not processed while the send budget was exhausted.'
        );
    }
}
