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
 * Authentication plugin class for FlexAccess.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/authlib.php');

/**
 * FlexAccess authentication plugin.
 *
 * The stub deliberately does not redirect from the core login page yet. The
 * target-aware redirect is enabled only after the policy resolver and loop/
 * redirect security tests are implemented.
 *
 * @package    auth_flexaccess
 */
class auth_plugin_flexaccess extends auth_plugin_base {
    /**
     * Constructor.
     */
    public function __construct() {
        $this->authtype = 'flexaccess';
        $this->config = get_config('auth_flexaccess');
    }

    /**
     * Validate a password for a FlexAccess user which has been activated.
     *
     * @param string $username Username.
     * @param string $password Password.
     * @return bool
     */
    public function user_login($username, $password): bool {
        global $DB;
        $user = $DB->get_record('user', ['username' => $username, 'auth' => 'flexaccess', 'deleted' => 0]);
        if (!$user || empty($password)) {
            return false;
        }
        $account = $DB->get_record('auth_flexaccess_account', ['userid' => $user->id]);
        if (
            !$account || $account->accounttype !== \auth_flexaccess\local\account_type::AUTHENTICATED_USER
                || $account->accountstate !== \auth_flexaccess\local\account_state::ACTIVE
        ) {
            return false;
        }
        return validate_internal_user_password($user, $password);
    }

    /**
     * FlexAccess stores local passwords only for activated users.
     *
     * @return bool
     */
    public function is_internal(): bool {
        return true;
    }

    /**
     * Advertise the FlexAccess entry point while preserving the requested URL.
     *
     * @param string $wantsurl Requested return URL.
     * @return array
     */
    public function loginpage_idp_list($wantsurl): array {
        // Only advertise FlexAccess when the wantsurl points at a real course/activity and that
        // course actually offers an anonymous FlexAccess entry method (window open + a method on).
        $target = \auth_flexaccess\local\target_resolver::resolve((string) $wantsurl);
        if ($target === null) {
            return [];
        }
        if (
            !class_exists(\enrol_flexaccess\api::class)
                || !\enrol_flexaccess\api::offers_anonymous_entry($target->courseid)
        ) {
            return [];
        }
        $url = new moodle_url('/auth/flexaccess/access.php', [
            'courseid' => $target->courseid,
            'wantsurl' => $wantsurl,
        ]);
        return [[
            'url' => $url,
            'icon' => new pix_icon('t/login', ''),
            'name' => get_string('accessprovider', 'auth_flexaccess'),
        ]];
    }

    /**
     * Login page hook. Kept intentionally non-redirecting in the scaffold.
     */
    public function pre_loginpage_hook(): void {
        // Phase 1: implement only after validated target resolution and redirect-loop tests exist.
    }
}
