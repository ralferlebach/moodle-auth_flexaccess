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
 * Mail-rate calculations for auth_flexaccess.
 *
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace auth_flexaccess\local;

/**
 * Pure helper for the rolling-hour FlexAccess mail limit.
 */
final class mail_rate {
    /**
     * Allowed limits; zero means unlimited.
     */
    public const ALLOWED_LIMITS = [0, 10, 50, 100, 500];

    /**
     * Calculate remaining capacity.
     *
     * @param int $limit Configured hourly limit, zero for unlimited.
     * @param int $sent Number sent in the preceding rolling hour.
     * @return int|null Null means unlimited.
     */
    public static function remaining(int $limit, int $sent): ?int {
        if (!in_array($limit, self::ALLOWED_LIMITS, true)) {
            throw new \coding_exception('Unsupported FlexAccess mail limit.');
        }
        if ($limit === 0) {
            return null;
        }
        return max(0, $limit - max(0, $sent));
    }
}
