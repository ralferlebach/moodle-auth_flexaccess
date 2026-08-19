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
 * Scheduled task processing the throttled mail queue.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace auth_flexaccess\task;

/**
 * Scheduled task that delivers queued FlexAccess mail (rendering tokens at delivery) and prunes rate hits.
 *
 * @package    auth_flexaccess
 */
final class process_mail_queue extends \core\task\scheduled_task {
    /**
     * Get the task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:processmailqueue', 'auth_flexaccess');
    }

    /**
     * Execute task.
     */
    public function execute(): void {
        \auth_flexaccess\local\mail_worker::run();
        // Housekeeping: drop rate-limit hit rows older than a day so the table stays small.
        \auth_flexaccess\local\rate_limiter::prune(time() - DAYSECS);
    }
}
