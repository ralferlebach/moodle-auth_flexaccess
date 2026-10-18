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

namespace auth_flexaccess\local;

/**
 * FlexAccess policy for identities that reach Moodle through LTI 1.3 (auth_lti) or another login path
 * that is not FlexAccess' own.
 *
 * Moodle's auth_lti binds a platform identity (iss, sub) to a local user in two places, and FlexAccess
 * enforces its lifecycle at both without patching Core:
 *
 * 1. Binding an existing account (auth/lti/login.php, "existing account"): Core binds whoever is
 *    logged in at that moment. Through Moodle's after_require_login callback, which that page passes,
 *    a FlexAccess identity may only be bound when it is a durable account - authenticated, ACTIVE,
 *    confirmed, with its own password and no verification pending. A temporary session (EPHEMERAL,
 *    PROVISIONAL, access-list card) or a PENDING_CREDENTIAL account is refused before Core writes the
 *    binding, and the pending launch is discarded so it cannot be retried with the same session.
 * 2. Launching as an already bound user (auth_lti::complete_login()): Core only checks the Moodle
 *    suspension flag and then calls complete_user_login(). The after_login_completed hook re-checks
 *    every FlexAccess account that was logged in by a path other than FlexAccess' own guarded logins
 *    against the same durable-account rule (which includes the login guard's expiry, suspension and
 *    state checks). A refused session is terminated at once - never turned into a guest session.
 *
 * Nothing here matches identities by e-mail or name: a binding is only ever created by Core for the
 * account the person is actually logged in with.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class lti_guard {
    /** The Core page that binds an LTI platform identity to an existing account. */
    public const BIND_SCRIPT = '/auth/lti/login.php';

    /** Login-guard channel used in refusal events for a refused binding. */
    public const CHANNEL_LTIBIND = 'ltibind';

    /** Login-guard channel used in refusal events for a refused non-FlexAccess login. */
    public const CHANNEL_EXTERNAL = 'external';

    /** Refusal: the account is temporary (EPHEMERAL, PROVISIONAL or an access-list card). */
    public const REASON_TEMPORARY = 'temporary';

    /** Refusal: the account has no own password yet. */
    public const REASON_NOCREDENTIAL = 'nocredential';

    /** Refusal: the e-mail verification of the account is still pending. */
    public const REASON_VERIFICATIONPENDING = 'verificationpending';

    /**
     * Whether a user may be bound to an LTI platform identity or signed in by a non-FlexAccess path.
     *
     * Users without a FlexAccess account are not FlexAccess' business and always pass.
     *
     * @param int $userid User id.
     * @param int|null $now Current time.
     * @return string '' when allowed, otherwise a login_guard REASON_* or one of this class' REASON_* codes.
     */
    public static function durable_identity_problem(int $userid, ?int $now = null): string {
        global $DB;
        $account = $DB->get_record('auth_flexaccess_account', ['userid' => $userid]);
        $user = $DB->get_record('user', ['id' => $userid], 'id, auth, password, deleted');
        if (!$user || $user->auth !== 'flexaccess') {
            // Another authentication method governs this user (an administrator changed it, or a merge
            // left the account row on an auth_lti user): not FlexAccess' decision. A missing user is
            // refused by Core itself.
            return '';
        }
        if (!$account) {
            return login_guard::REASON_NOACCOUNT;
        }
        if ((string) $account->accounttype === account_type::TEMPORARY_USER) {
            return self::REASON_TEMPORARY;
        }
        $reason = login_guard::evaluate($userid, login_guard::CHANNEL_PASSWORD, $now);
        if ($reason !== login_guard::OK) {
            return $reason;
        }
        $hash = $user ? (string) $user->password : '';
        if ($hash === '' || $hash === AUTH_PASSWORD_NOT_CACHED) {
            return self::REASON_NOCREDENTIAL;
        }
        $pendingemail = get_user_preferences('auth_flexaccess_pendingemail', null, $userid);
        if ($pendingemail !== null && $pendingemail !== '') {
            // A changed address that is not verified yet: the identity is not settled.
            return self::REASON_VERIFICATIONPENDING;
        }
        return '';
    }

    /**
     * Refuse binding a non-durable FlexAccess identity on Core's "existing account" page.
     *
     * Called from the after_require_login callback, so it runs on every require_login(); everything but
     * the binding request itself returns after a string comparison.
     *
     * @return void
     * @throws \moodle_exception When the logged-in FlexAccess identity must not be bound.
     */
    public static function check_binding_request(): void {
        global $SCRIPT, $SESSION, $USER;
        if ($SCRIPT !== self::BIND_SCRIPT || !optional_param('existing_account', false, PARAM_BOOL)) {
            return;
        }
        if (!isloggedin() || isguestuser()) {
            return;
        }
        $reason = self::durable_identity_problem((int) $USER->id);
        if ($reason === '') {
            return;
        }
        login_guard::log_refusal((int) $USER->id, self::CHANNEL_LTIBIND, $reason);
        // Discard the pending launch: the same session must not be able to complete the binding later.
        unset($SESSION->auth_lti);
        $link = self::is_temporary_reason($reason) ? new \moodle_url('/auth/flexaccess/persist.php') : null;
        throw new \moodle_exception('ltibindrefused', 'auth_flexaccess', $link ?? '');
    }

    /** Core pages that complete an LTI launch for the bound (already signed-in) user. */
    public const LAUNCH_SCRIPTS = ['/enrol/lti/launch.php', '/enrol/lti/launch_deeplink.php'];

    /**
     * Refuse an LTI launch of a FlexAccess identity that may no longer sign in.
     *
     * auth_lti::complete_login() skips complete_user_login() when the bound user is already signed in
     * (deep linking needs the session), so the after_login_completed hook never sees that launch. The
     * launch page calls require_login() right after, which is where this runs: the same rule as for a
     * new session, and a refused session is ended.
     *
     * @return void
     * @throws \moodle_exception When the session was ended.
     */
    public static function check_launch_session(): void {
        global $SCRIPT, $USER;
        if (!in_array($SCRIPT, self::LAUNCH_SCRIPTS, true) || !isloggedin() || isguestuser()) {
            return;
        }
        $userid = (int) $USER->id;
        $reason = self::durable_identity_problem($userid);
        if ($reason === '') {
            return;
        }
        login_guard::log_refusal($userid, self::CHANNEL_EXTERNAL, $reason);
        \core\session\manager::terminate_current();
        throw new \moodle_exception('externalloginrefused', 'auth_flexaccess');
    }

    /**
     * Re-check a FlexAccess account that was signed in by a path other than FlexAccess' guarded logins.
     *
     * @param int $userid The user that was just signed in.
     * @param int|null $now Current time.
     * @return string '' when the session may stay, otherwise the refusal reason (the session is ended).
     */
    public static function enforce_session(int $userid, ?int $now = null): string {
        if (login_guard::consume_guarded_login($userid)) {
            return '';
        }
        $reason = self::durable_identity_problem($userid, $now);
        if ($reason === '') {
            return '';
        }
        login_guard::log_refusal($userid, self::CHANNEL_EXTERNAL, $reason);
        \core\session\manager::terminate_current();
        return $reason;
    }

    /**
     * Whether a refusal reason means "make the temporary access permanent first".
     *
     * @param string $reason Reason code.
     * @return bool
     */
    private static function is_temporary_reason(string $reason): bool {
        return in_array($reason, [self::REASON_TEMPORARY, self::REASON_VERIFICATIONPENDING], true);
    }
}
