<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace auth_flexaccess\local;

/**
 * Magic (e-mail link) login: issuing and consuming one-time access links.
 *
 * Split out of the api facade so this part of the identity lifecycle can be reviewed on its own.
 * The facade keeps its signatures and delegates here.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class magic_service {
    /** Lifetime of a magic-login link. */
    private const MAGIC_LOGIN_TTL = 900;

    /** Sliding window for magic-login rate limiting. */
    private const MAGIC_RATE_WINDOW = 600;

    /** Maximum magic-login requests per IP within the window. */
    private const MAGIC_MAX_PER_IP = 15;

    /** Maximum magic-login requests per e-mail within the window. */
    private const MAGIC_MAX_PER_EMAIL = 3;

    /**
     * Whether passwordless magic-login links are offered.
     *
     * @return bool
     */
    public static function magic_login_enabled(): bool {
        $value = get_config('auth_flexaccess', 'allowmagiclogin');
        return $value === false ? true : (bool) $value;
    }

    /**
     * Request a passwordless magic-login link for a permanent FlexAccess account.
     *
     * To avoid revealing which addresses have accounts, this always reports success; a link is only
     * actually queued for a valid, active authenticated FlexAccess account. The token lifetime is
     * capped to the account's remaining validity so an expired account cannot be reactivated.
     *
     * @param string $email Email address (or username) entered by the user.
     * @param string|null $clientip Client address for rate limiting, or null to skip it.
     * @param int|null $now Current time.
     * @return string 'sent' normally, or 'disabled' when the feature is off.
     */
    public static function request_magic_login(string $email, ?string $clientip = null, ?int $now = null): string {
        global $DB;
        $now = $now ?? time();
        if (!self::magic_login_enabled()) {
            return 'disabled';
        }
        $email = \core_text::strtolower(trim($email));
        if ($email === '') {
            return 'sent';
        }

        // Rate limit per client and per target address (atomic, DB-backed). Both silently report
        // success so the endpoint never reveals whether an account exists and cannot be used to spam
        // a victim's inbox. Limits are admin-configurable; the constants are the fallback defaults.
        $maxperip = self::config_int('magicmaxperip', self::MAGIC_MAX_PER_IP);
        $maxperemail = self::config_int('magicmaxperemail', self::MAGIC_MAX_PER_EMAIL);
        $window = self::config_int('magicwindow', self::MAGIC_RATE_WINDOW);
        $blocked = ($clientip !== null && rate_limiter::hit('magic_ip', $clientip, $maxperip, $window, $now));
        $blocked = rate_limiter::hit('magic_email', $email, $maxperemail, $window, $now) || $blocked;
        if ($blocked) {
            return 'sent';
        }

        $user = $DB->get_record_select(
            'user',
            'deleted = 0 AND auth = :auth AND (LOWER(email) = :email OR username = :username)',
            ['auth' => 'flexaccess', 'email' => $email, 'username' => $email],
            '*',
            IGNORE_MULTIPLE
        );
        if ($user) {
            $account = \auth_flexaccess\api::get_account((int) $user->id);
            if (
                $account
                    && $account->accounttype === account_type::AUTHENTICATED_USER
                    && $account->accountstate === account_state::ACTIVE
            ) {
                $ttl = self::MAGIC_LOGIN_TTL;
                if ($account->timeexpires !== null) {
                    $ttl = min($ttl, max(0, (int) $account->timeexpires - $now));
                }
                if ($ttl > 0) {
                    \auth_flexaccess\api::queue_token_mail(
                        (int) $user->id,
                        $user->email,
                        mail_kind::MAGIC_LOGIN,
                        'magiclogin',
                        $ttl,
                        $now,
                        $now
                    );
                }
            }
        }
        return 'sent';
    }

    /**
     * Consume a magic-login token and return the user id to log in, or null if invalid.
     *
     * Re-checks the account is still a valid, active authenticated account at consume time.
     *
     * @param string $token Clear-text magic-login token.
     * @param int|null $now Current time.
     * @return int|null User id to log in, or null.
     */
    public static function consume_magic_login(string $token, ?int $now = null): ?int {
        $now = $now ?? time();
        $userid = token_service::consume($token, 'magiclogin', $now, null);
        if ($userid === null) {
            return null;
        }
        $account = \auth_flexaccess\api::get_account($userid);
        if (
            !$account
                || $account->accounttype !== account_type::AUTHENTICATED_USER
                || $account->accountstate !== account_state::ACTIVE
        ) {
            return null;
        }
        return $userid;
    }

    /**
     * Read a positive integer plugin setting, falling back to a default.
     *
     * @param string $name Setting name.
     * @param int $default Fallback when unset or not positive.
     * @return int
     */
    private static function config_int(string $name, int $default): int {
        $value = (int) get_config('auth_flexaccess', $name);
        return $value > 0 ? $value : $default;
    }
}
