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
 * Pure helper deciding how many queued mails may be sent in one run.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace auth_flexaccess\local;

/** Computes the number of mails to send under the rolling-hour throttle. */
final class mail_planner {
    /**
     * How many due mails may be sent given remaining capacity.
     *
     * @param int|null $remaining Remaining hourly capacity; null means unlimited.
     * @param int $duecount Number of mails currently due.
     * @return int
     */
    public static function sendable(?int $remaining, int $duecount): int {
        $duecount = max(0, $duecount);
        if ($remaining === null) {
            return $duecount;
        }
        return max(0, min($remaining, $duecount));
    }
}
