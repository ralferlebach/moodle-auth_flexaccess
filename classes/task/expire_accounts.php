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
 * Scheduled task expiring temporary/provisional accounts.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace auth_flexaccess\task;

/**
 * Scheduled task that expires due temporary accounts and purges them after the retention window.
 *
 * @package    auth_flexaccess
 */
final class expire_accounts extends \core\task\scheduled_task {
    /**
     * Get the task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:expireaccounts', 'auth_flexaccess');
    }

    /**
     * Execute task.
     */
    public function execute(): void {
        \auth_flexaccess\local\account_service::expire_due();
        // Persistence follow-up: remind pending-persistence users before their account lapses.
        \auth_flexaccess\api::send_persistence_followups();
        // Deletion lifecycle: purge accounts that expired longer ago than the retention window.
        $retentiondays = (int) get_config('auth_flexaccess', 'retentiondays');
        if ($retentiondays > 0) {
            \auth_flexaccess\local\account_service::purge_expired(null, $retentiondays * DAYSECS);
        }
    }
}
