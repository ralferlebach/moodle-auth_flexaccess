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
 * Account type constants for auth_flexaccess.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace auth_flexaccess\local;

/**
 * Business-facing account types.
 *
 * @package    auth_flexaccess
 */
final class account_type {
    /**
     * Temporary or not-yet-verified identity.
     */
    public const TEMPORARY_USER = 'temporary user';
    /**
     * Verified/regular identity.
     */
    public const AUTHENTICATED_USER = 'authenticated user';

    /**
     * Return all values.
     *
     * @return array<string>
     */
    public static function values(): array {
        return [self::TEMPORARY_USER, self::AUTHENTICATED_USER];
    }
}
