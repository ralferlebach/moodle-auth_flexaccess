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
 * Moodle callbacks of auth_flexaccess that have no hook equivalent.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Called at the end of every require_login(): refuses binding a non-durable FlexAccess identity to an
 * LTI platform identity on auth_lti's "existing account" page (see {@see \auth_flexaccess\local\lti_guard}).
 *
 * It also re-checks the user of an LTI launch: auth_lti skips complete_user_login() when the bound
 * user already has a session, so the after_login_completed hook does not see that launch.
 *
 * Moodle offers no hook for this point; both checks return after one string comparison on every
 * other page.
 *
 * @param stdClass|int|null $courseorid Course passed to require_login().
 * @param bool $autologinguest Whether guest auto-login was allowed.
 * @param cm_info|stdClass|null $cm Course module.
 * @param bool $setwantsurltome Whether wantsurl was set.
 * @param bool $preventredirect Whether redirects were prevented.
 * @return void
 */
function auth_flexaccess_after_require_login(
    $courseorid = null,
    $autologinguest = null,
    $cm = null,
    $setwantsurltome = null,
    $preventredirect = null
): void {
    \auth_flexaccess\local\lti_guard::check_binding_request();
    \auth_flexaccess\local\lti_guard::check_launch_session();
}

/**
 * Status checks contributed to Site administration > Reports > System status.
 *
 * @return \core\check\check[]
 */
function auth_flexaccess_status_checks(): array {
    return [new \auth_flexaccess\check\lifecycle_invariants()];
}
