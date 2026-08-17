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
 * Pure scheduling logic for persistence follow-up mails.
 *
 * A follow-up reminder must be planned to arrive *before* the temporary account expires. If
 * the account is too short-lived to fit a reminder ahead of a safety margin, no follow-up is
 * scheduled. A follow-up is only ever relevant while the account is still a temporary user and
 * has not yet been converted (ADR-013).
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace auth_flexaccess\local;

/** Stateless follow-up scheduling helpers. */
final class followup_scheduler {
    /** Default safety margin before expiry, in seconds. */
    public const DEFAULT_SAFETY_MARGIN = 3600;

    /**
     * Compute the effective send time for a follow-up, clamped before account expiry.
     *
     * @param int $createdtime Account creation time.
     * @param int $afterseconds Configured delay after creation.
     * @param int|null $expirytime Account expiry time; null/0 means no expiry.
     * @param int $safetymargin Seconds the follow-up must precede expiry by.
     * @return int|null Effective send time, or null when it cannot be fitted before expiry.
     */
    public static function due_time(int $createdtime, int $afterseconds, ?int $expirytime,
            int $safetymargin = self::DEFAULT_SAFETY_MARGIN): ?int {
        $planned = $createdtime + max(0, $afterseconds);
        if ($expirytime === null || $expirytime <= 0) {
            return $planned;
        }
        $latest = $expirytime - max(0, $safetymargin);
        if ($latest <= $createdtime) {
            return null;
        }
        return min($planned, $latest);
    }

    /**
     * Whether a follow-up should be scheduled for the given account state.
     *
     * Only unconverted temporary users in an ephemeral or provisional state qualify.
     *
     * @param string $accounttype Account type.
     * @param string $accountstate Account state.
     * @return bool
     */
    public static function should_schedule(string $accounttype, string $accountstate): bool {
        if ($accounttype !== account_type::TEMPORARY_USER) {
            return false;
        }
        return in_array($accountstate, [account_state::EPHEMERAL, account_state::PROVISIONAL], true);
    }
}
