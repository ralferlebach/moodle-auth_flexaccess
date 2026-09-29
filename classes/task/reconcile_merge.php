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
 * Reconcile FlexAccess state after an external identity merge, outside the merging request.
 *
 * Queued by the tool_mergeusers observer: the reconciliation touches every FlexAccess enrolment of the
 * merged-away identity (about 35 queries per course), which does not belong into the synchronous
 * request of another plugin. The reconciliation itself is idempotent, so a retry by the task
 * framework is safe.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reconcile_merge extends \core\task\adhoc_task {
    /**
     * Queue the reconciliation of one merge (duplicates of the same pair are not queued twice).
     *
     * @param int $fromuserid Identity that was merged away.
     * @param int $touserid Surviving identity.
     * @return void
     */
    public static function queue(int $fromuserid, int $touserid): void {
        $task = new self();
        $task->set_component('auth_flexaccess');
        $task->set_custom_data(['from' => $fromuserid, 'to' => $touserid]);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Run the reconciliation.
     *
     * @return void
     */
    public function execute() {
        $data = (array) $this->get_custom_data();
        $from = (int) ($data['from'] ?? 0);
        $to = (int) ($data['to'] ?? 0);
        if ($from > 0 && $to > 0) {
            $result = \auth_flexaccess\api::reconcile_external_identity_merge($from, $to, false);
            mtrace("FlexAccess merge {$from} -> {$to}: {$result->status}");
        }
    }
}
