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
 * Scheduled task deleting settled merge evidence after its retention period.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace auth_flexaccess\task;

/**
 * Deletes archived merge collisions that are settled and older than the retention period (auth #13).
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class purge_merge_evidence extends \core\task\scheduled_task {
    /** Default retention of settled evidence, in days. */
    public const DEFAULT_RETENTION_DAYS = 365;

    /**
     * Task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('taskpurgemergeevidence', 'auth_flexaccess');
    }

    /**
     * Retention in days (0 keeps everything).
     *
     * @return int
     */
    public static function retention_days(): int {
        $value = get_config('auth_flexaccess', 'mergeevidenceretention');
        return $value === false || $value === '' ? self::DEFAULT_RETENTION_DAYS : max(0, (int) $value);
    }

    /**
     * Delete settled evidence older than the retention period.
     *
     * @return void
     */
    public function execute(): void {
        $deleted = \auth_flexaccess\local\merge_evidence::purge_settled(self::retention_days());
        mtrace("auth_flexaccess: deleted $deleted settled merge evidence record(s).");
    }
}
