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
 * Lifecycle state constants for auth_flexaccess.
 *
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace auth_flexaccess\local;

/**
 * Account lifecycle states.
 */
final class account_state {
    /** Ephemeral: a temporary user with no captured contact details. */
    public const EPHEMERAL = 'ephemeral';
    /** Provisional: a temporary user who has provided contact details. */
    public const PROVISIONAL = 'provisional';
    /** Active: a confirmed, authenticated account. */
    public const ACTIVE = 'active';
    /** Expired: a temporary account whose lifetime has passed. */
    public const EXPIRED = 'expired';
    /** Suspended: an account blocked from access. */
    public const SUSPENDED = 'suspended';

    /**
     * All account states.
     *
     * @return array<string>
     */
    public static function values(): array {
        return [self::EPHEMERAL, self::PROVISIONAL, self::ACTIVE, self::EXPIRED, self::SUSPENDED];
    }
}
