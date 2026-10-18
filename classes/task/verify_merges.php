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

namespace auth_flexaccess\task;

/**
 * Verify registered identity merges that are still pending (backfill after upgrade, interrupted runs).
 *
 * Idempotent: every pair is verified with {@see \auth_flexaccess\local\merge_service::verify()}; a pair
 * whose postconditions fail stays "review" and surfaces in the system status.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class verify_merges extends \core\task\adhoc_task {
    /** Pairs verified per run; the task re-queues itself while more remain. */
    private const BATCH = 200;

    /**
     * Verify a batch of pending pairs.
     *
     * @return void
     */
    public function execute() {
        global $DB;
        $rows = $DB->get_records(
            \auth_flexaccess\local\merge_registry::TABLE,
            ['status' => \auth_flexaccess\local\merge_registry::PENDING],
            'id ASC',
            'id, fromuserid, touserid',
            0,
            self::BATCH
        );
        foreach ($rows as $row) {
            $result = \auth_flexaccess\local\merge_service::verify((int) $row->fromuserid, (int) $row->touserid);
            if ($result->status === 'invalid') {
                // The surviving user is gone: nothing left to reconcile, the pair is closed for review.
                $registry = \auth_flexaccess\local\merge_registry::get((int) $row->fromuserid, (int) $row->touserid);
                \auth_flexaccess\local\merge_registry::finish($registry, \auth_flexaccess\local\merge_registry::REVIEW, [
                    \auth_flexaccess\local\merge_guard::TARGET_MISSING,
                ], []);
            }
            mtrace("FlexAccess merge {$row->fromuserid} -> {$row->touserid}: {$result->status}");
        }
        if (count($rows) === self::BATCH) {
            \core\task\manager::queue_adhoc_task(new self(), true);
        }
    }
}
