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
 * Public facade for auth_flexaccess.
 *
 * The stable cross-plugin entry point consumed by enrol/tool/mod. It classifies users, reads
 * account metadata and enqueues persistence follow-up mails. Tokens and the actual sending are
 * handled by the mail worker; this facade never places a secret in the queue.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace auth_flexaccess;

use auth_flexaccess\local\account_type;
use auth_flexaccess\local\account_state;
use auth_flexaccess\local\account_service;
use auth_flexaccess\local\token_service;
use auth_flexaccess\local\mail_kind;

/**
 * Cross-plugin facade for FlexAccess account classification and the follow-up funnel.
 *
 * @package    auth_flexaccess
 */
final class api {
    /**
     * Mail-queue table.
     */
    private const QUEUE_TABLE = 'auth_flexaccess_mailqueue';

    /**
     * Magic-login token lifetime in seconds (15 minutes).
     */
    private const MAGIC_LOGIN_TTL = 900;

    /**
     * Set-password invitation lifetime in seconds (3 days) for admin-initiated conversions.
     */
    private const SET_PASSWORD_TTL = 259200;

    /**
     * Sliding window for magic-login request rate limiting, in seconds.
     */
    private const MAGIC_RATE_WINDOW = 600;

    /**
     * Maximum magic-login requests per client address within the window.
     */
    private const MAGIC_MAX_PER_IP = 15;

    /**
     * Maximum magic-login requests per target address within the window (anti inbox-spam).
     */
    private const MAGIC_MAX_PER_EMAIL = 3;

    /**
     * Read a positive integer plugin setting, falling back to a default when unset or non-positive.
     *
     * @param string $name Setting name within auth_flexaccess.
     * @param int $default Fallback value.
     * @return int
     */
    private static function config_int(string $name, int $default): int {
        $value = (int) get_config('auth_flexaccess', $name);
        return $value > 0 ? $value : $default;
    }
    /**
     * Account table.
     */
    private const ACCOUNT_TABLE = 'auth_flexaccess_account';

    /**
     * Classify a user as a FlexAccess account type.
     *
     * A user without a FlexAccess account record is treated as an authenticated user, so
     * callers can branch safely without a FlexAccess metadata row existing.
     *
     * @param int $userid User id.
     * @return string One of account_type::TEMPORARY_USER or account_type::AUTHENTICATED_USER.
     */
    public static function classify_user(int $userid): string {
        $account = self::get_account($userid);
        if ($account && $account->accounttype === account_type::TEMPORARY_USER) {
            return account_type::TEMPORARY_USER;
        }
        return account_type::AUTHENTICATED_USER;
    }

    /**
     * Load the FlexAccess account metadata for a user.
     *
     * @param int $userid User id.
     * @return \stdClass|null
     */
    public static function get_account(int $userid): ?\stdClass {
        global $DB;
        $record = $DB->get_record(self::ACCOUNT_TABLE, ['userid' => $userid]);
        return $record ?: null;
    }

    /**
     * Self-activate a logged-in temporary user, capturing e-mail and name.
     *
     * Immediate in-session activation: the temporary user is already authenticated in the
     * session, so this converts the same account to an authenticated user after capturing a
     * deliverable, non-duplicate e-mail. The e-mail-verified path is available separately via the
     * follow-up funnel. Enrolment and its duration are not changed.
     *
     * @param int $userid Logged-in user id.
     * @param string $email Captured e-mail address.
     * @param string $password Password chosen by the present user so the account is re-loginnable.
     * @param string $firstname Optional first name to set.
     * @param string $lastname Optional last name to set.
     * @return string One of: activated, notapplicable, invalidemail, emailtaken.
     */
    public static function self_activate(
        int $userid,
        string $email,
        string $password,
        string $firstname = '',
        string $lastname = ''
    ): string {
        // Do not finalise an unverified email as an identity: start the persistence/verification
        // funnel. With verification enabled (the default) this emails an activation link; only when
        // an admin has disabled verification does it convert immediately.
        $status = self::request_persistence($userid, $email, $firstname, $lastname, $password, time());
        if ($status === 'converted') {
            return 'activated';
        }
        return $status;
    }

    /**
     * Convert a temporary account to an authenticated identity atomically.
     *
     * Validates the target email, rewrites the user's identity (email, username, name) and flips the
     * FlexAccess account to authenticated inside a single transaction, so a partial failure never
     * leaves a half-converted account (§13). An optional callback runs inside the same transaction to
     * attach a credential (e.g. a password) atomically with the conversion.
     *
     * @param int $userid User id of the temporary account.
     * @param string $email New login email.
     * @param string $firstname Optional replacement first name.
     * @param string $lastname Optional replacement last name.
     * @param int $now Current time.
     * @param callable|null $inside Optional callback executed within the transaction after conversion.
     * @return string 'ok' on success, or 'notapplicable'|'invalidemail'|'emailtaken'.
     */
    private static function finalise_identity(
        int $userid,
        string $email,
        string $firstname,
        string $lastname,
        int $now,
        ?callable $inside = null
    ): string {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/user/lib.php');

        // Serialise all conversions for this user so self-, admin- and verification-initiated
        // conversions cannot race. The guards below run inside the lock, so a second concurrent
        // attempt sees the account already converted and returns 'notapplicable'.
        $lockfactory = \core\lock\lock_config::get_lock_factory('auth_flexaccess_conversion');
        $lock = $lockfactory->get_lock('user_' . $userid, 10);
        if (!$lock) {
            return 'locked';
        }
        try {
            if (!account_service::is_convertible($userid, $now)) {
                return 'notapplicable';
            }
            $email = \core_text::strtolower(trim($email));
            if ($email === '' || !validate_email($email)) {
                return 'invalidemail';
            }
            if (!self::email_available($email, $userid)) {
                return 'emailtaken';
            }

            $transaction = $DB->start_delegated_transaction();
            try {
                $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
                $user->email = $email;
                $user->username = $email;
                $user->emailstop = 0;
                if (trim($firstname) !== '') {
                    $user->firstname = clean_param($firstname, PARAM_NOTAGS);
                }
                if (trim($lastname) !== '') {
                    $user->lastname = clean_param($lastname, PARAM_NOTAGS);
                }
                if (method_exists(\core\user::class, 'update_user')) {
                    \core\user::update_user($user, false, true);
                } else {
                    user_update_user($user, false, true);
                }
                account_service::convert_to_authenticated($userid, $now);
                if ($inside !== null) {
                    $inside();
                }
                $transaction->allow_commit();
            } catch (\Throwable $e) {
                // Roll back so a failure in the credential/token callback cannot leave a
                // half-converted account (and a consumed single-use token stays unconsumed).
                $transaction->rollback($e);
            }
            return 'ok';
        } finally {
            $lock->release();
        }
    }

    /**
     * Persist the current temporary user: give the existing account a real identity so it survives.
     *
     * This keeps the SAME user id, so all enrolments, results and activity are retained; the user
     * simply gains their own email, name and password and becomes a permanent, loginnable account.
     *
     * @param int $userid Temporary user's id.
     * @param string $email Email address the user provides (also becomes the username).
     * @param string $firstname First name.
     * @param string $lastname Last name.
     * @param string $password Clear-text password chosen by the user.
     * @param int|null $now Current time.
     * @return string Status: 'converted', 'nottemporary' or 'emailtaken'.
     */
    public static function persist_temporary_user(
        int $userid,
        string $email,
        string $firstname,
        string $lastname,
        string $password,
        ?int $now = null
    ): string {
        global $CFG;
        require_once($CFG->dirroot . '/user/lib.php');
        $now = $now ?? time();

        // Route through the single, locked conversion path (which uses is_convertible, not merely
        // is_temporary) so an already-expired account can never be revived, and set the password
        // inside the same transaction.
        $status = self::finalise_identity(
            $userid,
            $email,
            $firstname,
            $lastname,
            $now,
            static function () use ($userid, $password): void {
                global $DB;
                $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
                $user->suspended = 0;
                update_internal_user_password($user, $password);
            }
        );
        if ($status === 'ok') {
            return 'converted';
        }
        return $status === 'notapplicable' ? 'nottemporary' : $status;
    }

    /**
     * Whether email verification is required before a temporary account is made permanent.
     *
     * Enabled by default; administrators can turn it off in the auth_flexaccess settings.
     *
     * @return bool
     */
    public static function email_verification_required(): bool {
        $value = get_config('auth_flexaccess', 'requireemailverification');
        return $value === false ? true : (bool) $value;
    }

    /**
     * Begin persisting a temporary account: convert immediately, or send an email verification link.
     *
     * When verification is disabled this converts straight away (see {@see self::persist_temporary_user()}).
     * When enabled, the chosen name and password are stored on the still-temporary account, the
     * pending email is remembered and a verification link is emailed; the account only becomes
     * permanent once the link is opened. The squatting-sensitive email/username is set only on
     * confirmation, so an unverified request cannot claim someone else's address.
     *
     * @param int $userid Temporary user's id.
     * @param string $email Email address the user provides.
     * @param string $firstname First name.
     * @param string $lastname Last name.
     * @param string $password Clear-text password chosen by the user.
     * @param int|null $now Current time.
     * @return string Status: 'converted', 'verificationsent', 'nottemporary' or 'emailtaken'.
     */
    public static function request_persistence(
        int $userid,
        string $email,
        string $firstname,
        string $lastname,
        string $password,
        ?int $now = null
    ): string {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/user/lib.php');
        $now = $now ?? time();
        $email = \core_text::strtolower(trim($email));

        if (!account_service::is_convertible($userid, $now)) {
            return 'nottemporary';
        }
        if ($email === '' || !validate_email($email)) {
            return 'invalidemail';
        }
        if (!self::email_available($email, $userid)) {
            return 'emailtaken';
        }
        if (!self::email_verification_required()) {
            return self::persist_temporary_user($userid, $email, $firstname, $lastname, $password, $now);
        }

        $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
        $user->firstname = $firstname;
        $user->lastname = $lastname;
        if (method_exists(\core\user::class, 'update_user')) {
            \core\user::update_user($user, false, true);
        } else {
            user_update_user($user, false, true);
        }
        update_internal_user_password($user, $password);

        set_user_preference('auth_flexaccess_pendingemail', $email, $userid);
        // The account is no longer a bare anonymous visitor: persistence has been requested and is
        // awaiting email verification. Reflect that in the state machine.
        account_service::mark_provisional($userid, $now);
        // SEC-03: the verification link must not outlive the temporary account it would revive.
        // The token itself is issued by the worker at delivery time (never persisted in the queue).
        $ttl = token_service::DEFAULT_TTL;
        $account = self::get_account($userid);
        if ($account && $account->timeexpires !== null) {
            $ttl = min($ttl, max(1, (int) $account->timeexpires - $now));
        }
        self::queue_token_mail($userid, $email, mail_kind::VERIFICATION, 'persistence', $ttl, null, $now);
        return 'verificationsent';
    }

    /**
     * Send one-time persistence reminders to temporary accounts approaching expiry.
     *
     * Closes the "mandatory persistence follow-up" of the account lifecycle: anonymous temporary
     * accounts cannot be emailed (their address is a non-deliverable placeholder), but a visitor who
     * has already started persistence has supplied a real pending address. Those users, when their
     * account is within the follow-up window of expiring and they have not yet been reminded, receive
     * one fresh verification link so an abandoned activation can still be completed. The reminder is
     * sent at most once per user (tracked by a preference) and the worker revokes any prior token on
     * delivery, so only one live link ever exists.
     *
     * @param int|null $now Current time.
     * @param int|null $window Seconds before expiry within which to remind; null reads config; <=0 disables.
     * @param int $limit Maximum accounts to process in one run.
     * @return int Number of reminders queued.
     */
    public static function send_persistence_followups(?int $now = null, ?int $window = null, int $limit = 200): int {
        global $DB;
        $now = $now ?? time();
        $window = $window ?? (int) get_config('auth_flexaccess', 'followupwindow');
        if ($window <= 0) {
            return 0;
        }
        $select = 'accounttype = :type AND accountstate <> :expired AND accountstate <> :suspended '
            . 'AND timeexpires > :now AND timeexpires <= :upper';
        $params = [
            'type' => account_type::TEMPORARY_USER,
            'expired' => account_state::EXPIRED,
            'suspended' => account_state::SUSPENDED,
            'now' => $now,
            'upper' => $now + $window,
        ];
        $records = $DB->get_records_select(
            'auth_flexaccess_account',
            $select,
            $params,
            'timeexpires ASC',
            '*',
            0,
            max(0, $limit)
        );
        $sent = 0;
        foreach ($records as $account) {
            $userid = (int) $account->userid;
            $pending = get_user_preferences('auth_flexaccess_pendingemail', null, $userid);
            if (!$pending) {
                continue;
            }
            if (get_user_preferences('auth_flexaccess_followupsent', null, $userid)) {
                continue;
            }
            $ttl = min(token_service::DEFAULT_TTL, max(1, (int) $account->timeexpires - $now));
            self::queue_token_mail($userid, (string) $pending, mail_kind::VERIFICATION, 'persistence', $ttl, null, $now);
            set_user_preference('auth_flexaccess_followupsent', $now, $userid);
            $sent++;
        }
        return $sent;
    }

    /**
     * Complete a verified persistence: consume the token and make the account permanent.
     *
     * The token authorises on its own (it was mailed to the pending address), so no login is
     * required to open the link.
     *
     * @param string $token Clear-text verification token from the emailed link.
     * @param int|null $now Current time.
     * @return string Status: 'converted', 'invalid' or 'emailtaken'.
     */
    public static function confirm_persistence(string $token, ?int $now = null): string {
        global $CFG;
        require_once($CFG->dirroot . '/user/lib.php');
        $now = $now ?? time();

        // Verify the token WITHOUT consuming it. Consumption happens inside the locked conversion
        // (see the $inside callback), so a conversion that fails afterwards never burns the
        // single-use token, and concurrent verification/admin conversions are serialised.
        $record = token_service::verify($token, 'persistence', $now);
        if ($record === null) {
            return 'invalid';
        }
        $userid = (int) $record->userid;
        if (!account_service::is_temporary($userid)) {
            // Link already used and the account is already permanent.
            return 'converted';
        }
        $pending = get_user_preferences('auth_flexaccess_pendingemail', null, $userid);
        if ($pending === null || $pending === '') {
            return 'invalid';
        }

        try {
            $status = self::finalise_identity(
                $userid,
                (string) $pending,
                '',
                '',
                $now,
                static function () use ($userid, $token, $now): void {
                    global $DB;
                    // Consume the single-use token inside the transaction. If it was already consumed
                    // by a concurrent request, abort so the whole conversion rolls back.
                    if (token_service::consume($token, 'persistence', $now, $userid) === null) {
                        throw new \moodle_exception('error');
                    }
                    $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
                    if ((int) $user->suspended === 1) {
                        $user->suspended = 0;
                        if (method_exists(\core\user::class, 'update_user')) {
                            \core\user::update_user($user, false, true);
                        } else {
                            user_update_user($user, false, true);
                        }
                    }
                    unset_user_preference('auth_flexaccess_pendingemail', $userid);
                    unset_user_preference('auth_flexaccess_followupsent', $userid);
                }
            );
        } catch (\moodle_exception $e) {
            // The token was consumed concurrently; nothing was converted.
            return 'invalid';
        }

        if ($status === 'ok') {
            return 'converted';
        }
        // Map the central guard's vocabulary onto this endpoint's.
        return $status === 'notapplicable' ? 'expired' : $status;
    }

    /**
     * Queue a generic mail for asynchronous, rate-limited delivery by the mail worker.
     *
     * All FlexAccess mails go through this queue so the site's outbound hourly limit is honoured.
     *
     * @param int|null $userid Related user id (nullable).
     * @param string $recipient Destination email address.
     * @param string $subject Subject line.
     * @param string $body Plain-text body.
     * @param string $bodyhtml Optional HTML body.
     * @param string $mailtype A {@see mail_kind} label for inspection (delivery is payload-driven).
     * @param int|null $nextrun Earliest send time; defaults to now.
     * @return int Queue row id.
     */
    public static function queue_mail(
        ?int $userid,
        string $recipient,
        string $subject,
        string $body,
        string $bodyhtml = '',
        string $mailtype = mail_kind::ACTIVATION,
        ?int $nextrun = null
    ): int {
        global $DB;
        $now = time();
        return (int) $DB->insert_record(self::QUEUE_TABLE, (object) [
            'userid' => $userid,
            'recipient' => $recipient,
            'mailtype' => $mailtype,
            'payloadjson' => json_encode(['subject' => $subject, 'body' => $body, 'bodyhtml' => $bodyhtml]),
            'status' => 'queued',
            'attempts' => 0,
            'timecreated' => $now,
            'nextrun' => $nextrun ?? $now,
            'timesent' => null,
        ]);
    }

    /**
     * Queue a token-bearing mail without persisting the secret.
     *
     * Only the mail type, recipient, subject user and token parameters are stored. The token itself
     * is created, hashed and rendered into the link by the worker at delivery time, so no plaintext
     * secret is ever written to {@see self::QUEUE_TABLE} or exposed via the privacy export.
     *
     * @param int $userid Subject user id (the token owner).
     * @param string $recipient Destination email address.
     * @param string $mailtype One of the mail_kind values.
     * @param string $purpose Token purpose to issue at delivery time.
     * @param int $ttl Token lifetime in seconds.
     * @param int|null $nextrun Earliest delivery time.
     * @param int|null $now Current time.
     * @return int Queue row id.
     */
    public static function queue_token_mail(
        int $userid,
        string $recipient,
        string $mailtype,
        string $purpose,
        int $ttl,
        ?int $nextrun = null,
        ?int $now = null
    ): int {
        global $DB;
        $now = $now ?? time();
        return (int) $DB->insert_record(self::QUEUE_TABLE, (object) [
            'userid' => $userid,
            'recipient' => $recipient,
            'mailtype' => $mailtype,
            'payloadjson' => json_encode(['kind' => 'token', 'purpose' => $purpose, 'ttl' => $ttl]),
            'status' => 'queued',
            'attempts' => 0,
            'timecreated' => $now,
            'nextrun' => $nextrun ?? $now,
            'timesent' => null,
        ]);
    }

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
        $blocked = ($clientip !== null && local\rate_limiter::hit('magic_ip', $clientip, $maxperip, $window, $now));
        $blocked = local\rate_limiter::hit('magic_email', $email, $maxperemail, $window, $now) || $blocked;
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
            $account = self::get_account((int) $user->id);
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
                    self::queue_token_mail(
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
        $account = self::get_account($userid);
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
     * Roll back a just-created temporary account (compensation for a failed provisioning flow).
     *
     * Only a still-temporary account is removed, so a converted account can never be deleted by a
     * late compensation. Removes the enrolment (via the core user delete) and the FlexAccess rows.
     *
     * @param int $userid Moodle user id.
     * @return bool Whether the account was rolled back.
     */
    public static function rollback_temporary_user(int $userid): bool {
        if (!account_service::is_temporary($userid)) {
            return false;
        }
        account_service::delete_account($userid);
        return true;
    }

    /**
     * Whether an email address is free to use for a new FlexAccess quick registration.
     *
     * @param string $email Candidate email address.
     * @param int|null $excludeuserid Optional user id to exclude (e.g. the current user).
     * @return bool True when no other non-deleted user already uses it as email or username.
     */
    public static function email_available(string $email, ?int $excludeuserid = null): bool {
        global $DB;
        $email = \core_text::strtolower(trim($email));
        if ($email === '') {
            return false;
        }
        $params = ['email' => $email, 'username' => $email];
        $exclude = '';
        if ($excludeuserid !== null) {
            $exclude = ' AND id <> :excludeid';
            $params['excludeid'] = $excludeuserid;
        }
        return !$DB->record_exists_select(
            'user',
            'deleted = 0 AND (LOWER(email) = :email OR username = :username)' . $exclude,
            $params
        );
    }

    /**
     * Create a temporary Moodle user with FlexAccess account metadata.
     *
     * The user is created with the FlexAccess auth method, a generated username, no local
     * password (set only on activation) and a non-deliverable placeholder e-mail with mail
     * disabled. The account lifetime (expiry) is supplied by the caller from the enrol policy.
     *
     * @param int|null $timeexpires Account expiry time; null means no expiry.
     * @param int|null $sourcecourseid Course the access originated from.
     * @param int|null $sourcecmid Course module the access originated from.
     * @param int|null $now Current time.
     * @return int The new user id.
     */
    public static function create_temporary_user(
        ?int $timeexpires = null,
        ?int $sourcecourseid = null,
        ?int $sourcecmid = null,
        ?int $now = null
    ): int {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/user/lib.php');
        $now = $now ?? time();

        // Numeric reference so temporary users can read it out to an administrator (see tool_flexaccess
        // reference search). Generated collision-free against existing accounts before the user is made.
        $reference = account_service::generate_unique_reference();
        do {
            $username = \core_text::strtolower('flexaccess_' . $reference . random_string(4));
        } while ($DB->record_exists('user', ['username' => $username, 'mnethostid' => $CFG->mnet_localhost_id]));

        $user = new \stdClass();
        $user->auth = 'flexaccess';
        $user->confirmed = 1;
        $user->mnethostid = $CFG->mnet_localhost_id;
        $user->username = $username;
        $user->password = '';
        $user->firstname = get_string('temporaryfirstname', 'auth_flexaccess');
        $user->lastname = get_string('temporarylastname', 'auth_flexaccess');
        $user->email = $username . '@flexaccess.invalid';
        $user->emailstop = 1;

        // Moodle 5.3 deprecates the global user_create_user() in favour of \core\user::create_user();
        // use the new API where available and fall back for 4.5-5.2.
        if (method_exists(\core\user::class, 'create_user')) {
            $userid = (int) \core\user::create_user($user, false, true);
        } else {
            $userid = (int) user_create_user($user, false, true);
        }
        account_service::create_temporary($userid, $reference, $timeexpires, $sourcecourseid, $sourcecmid, $now);
        return $userid;
    }

    /**
     * Create a batch account with a caller-supplied username and password, enrol-ready.
     *
     * Used by the tool batch-provisioning feature: an administrator generates many accounts with
     * random credentials for a course. The account is temporary (restricted, expiring) unless
     * $permanent is set, in which case it is a full authenticated account (still with a placeholder
     * email until it is later personalised). The password is stored hashed, never in plain text.
     *
     * @param string $username Desired username (lower-cased).
     * @param string $password Plain password to set (hashed on store).
     * @param string $firstname First name (may be a placeholder).
     * @param string $lastname Last name (may be a placeholder).
     * @param bool $permanent Whether to create a permanent authenticated account.
     * @param int|null $timeexpires Expiry for temporary accounts (ignored when permanent).
     * @param int|null $now Current time.
     * @return int New user id.
     */
    public static function create_batch_account(
        string $username,
        string $password,
        string $firstname,
        string $lastname,
        bool $permanent,
        ?int $timeexpires = null,
        ?int $now = null
    ): int {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/user/lib.php');
        $now = $now ?? time();
        $username = \core_text::strtolower(trim($username));

        $user = new \stdClass();
        $user->auth = 'flexaccess';
        $user->confirmed = 1;
        $user->mnethostid = $CFG->mnet_localhost_id;
        $user->username = $username;
        $user->password = '';
        $user->firstname = $firstname !== '' ? $firstname : get_string('temporaryfirstname', 'auth_flexaccess');
        $user->lastname = $lastname !== '' ? $lastname : get_string('temporarylastname', 'auth_flexaccess');
        $user->email = $username . '@flexaccess.invalid';
        $user->emailstop = 1;

        if (method_exists(\core\user::class, 'create_user')) {
            $userid = (int) \core\user::create_user($user, false, true);
        } else {
            $userid = (int) user_create_user($user, false, true);
        }
        // Store the password hashed.
        $stored = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
        update_internal_user_password($stored, $password);

        $reference = account_service::generate_unique_reference();
        if ($permanent) {
            account_service::create_authenticated($userid, $reference, $now);
        } else {
            account_service::create_temporary($userid, $reference, $timeexpires, null, null, $now);
        }
        return $userid;
    }

    /**
     * Whether a username is free (not used by another non-deleted local account).
     *
     * @param string $username Username to check (lower-cased).
     * @param int|null $excludeuserid User id to exclude from the check.
     * @return bool
     */
    public static function username_available(string $username, ?int $excludeuserid = null): bool {
        global $DB, $CFG;
        $username = \core_text::strtolower(trim($username));
        if ($username === '') {
            return false;
        }
        $params = ['username' => $username, 'mnethostid' => $CFG->mnet_localhost_id];
        $exclude = '';
        if ($excludeuserid !== null) {
            $exclude = ' AND id <> :excludeid';
            $params['excludeid'] = $excludeuserid;
        }
        return !$DB->record_exists_select(
            'user',
            'deleted = 0 AND username = :username AND mnethostid = :mnethostid' . $exclude,
            $params
        );
    }

    /**
     * Rename an account's username, validating format and availability.
     *
     * @param int $userid User id.
     * @param string $username Desired username.
     * @return bool Whether the rename was applied.
     */
    public static function rename_username(int $userid, string $username): bool {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/user/lib.php');
        $username = clean_param(\core_text::strtolower(trim($username)), PARAM_USERNAME);
        if ($username === '' || !self::username_available($username, $userid)) {
            return false;
        }
        $user = $DB->get_record('user', ['id' => $userid], '*', IGNORE_MISSING);
        if (!$user) {
            return false;
        }
        $user->username = $username;
        if (method_exists(\core\user::class, 'update_user')) {
            \core\user::update_user($user, false, true);
        } else {
            user_update_user($user, false, true);
        }
        return true;
    }

    /**
     * Reset a batch account's password to a new plain value (hashed on store).
     *
     * @param int $userid User id.
     * @param string $password New plain password.
     * @return bool Whether the password was set.
     */
    public static function set_account_password(int $userid, string $password): bool {
        global $DB;
        $user = $DB->get_record('user', ['id' => $userid], '*', IGNORE_MISSING);
        if (!$user) {
            return false;
        }
        return (bool) update_internal_user_password($user, $password);
    }

    /**
     * Aggregate account counts for dashboards.
     *
     * @return array<string, int>
     */
    public static function account_stats(): array {
        global $DB;
        $t = self::ACCOUNT_TABLE;
        // A single conditional-aggregate pass instead of six separate COUNT queries.
        $row = $DB->get_record_sql(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN accounttype = :tt THEN 1 ELSE 0 END) AS temporary,
                    SUM(CASE WHEN accounttype = :ta THEN 1 ELSE 0 END) AS authenticated,
                    SUM(CASE WHEN accountstate = :sp THEN 1 ELSE 0 END) AS provisional,
                    SUM(CASE WHEN accountstate = :sac THEN 1 ELSE 0 END) AS active,
                    SUM(CASE WHEN accountstate = :se THEN 1 ELSE 0 END) AS expired
               FROM {" . $t . "}",
            [
                'tt' => account_type::TEMPORARY_USER,
                'ta' => account_type::AUTHENTICATED_USER,
                'sp' => account_state::PROVISIONAL,
                'sac' => account_state::ACTIVE,
                'se' => account_state::EXPIRED,
            ]
        );
        return [
            'total' => (int) $row->total,
            'temporary' => (int) $row->temporary,
            'authenticated' => (int) $row->authenticated,
            'provisional' => (int) $row->provisional,
            'active' => (int) $row->active,
            'expired' => (int) $row->expired,
        ];
    }

    /**
     * Summarise the mail queue for dashboards.
     *
     * @return array<string, int>
     */
    public static function mailqueue_summary(): array {
        global $DB;
        $nextdue = $DB->get_field_sql(
            "SELECT MIN(nextrun) FROM {" . self::QUEUE_TABLE . "} WHERE status = ?",
            ['queued']
        );
        return [
            'queued' => $DB->count_records(self::QUEUE_TABLE, ['status' => 'queued']),
            'sent' => $DB->count_records(self::QUEUE_TABLE, ['status' => 'sent']),
            'failed' => $DB->count_records(self::QUEUE_TABLE, ['status' => 'failed']),
            'nextdue' => $nextdue ? (int) $nextdue : 0,
        ];
    }

    /**
     * Count mail-queue rows, optionally filtered by status.
     *
     * @param string $status Optional status filter.
     * @return int
     */
    public static function count_mailqueue(string $status = ''): int {
        global $DB;
        $conditions = $status !== '' ? ['status' => $status] : [];
        return $DB->count_records(self::QUEUE_TABLE, $conditions);
    }

    /**
     * List mail-queue rows (no secrets; payload is excluded), newest first.
     *
     * @param string $status Optional status filter.
     * @param int $page Zero-based page index.
     * @param int $perpage Page size.
     * @return array<\stdClass>
     */
    public static function list_mailqueue(string $status = '', int $page = 0, int $perpage = 50): array {
        global $DB;
        $conditions = $status !== '' ? ['status' => $status] : [];
        $rows = $DB->get_records(
            self::QUEUE_TABLE,
            $conditions,
            'timecreated DESC',
            'id, recipient, mailtype, status, attempts, timecreated, nextrun, timesent',
            $page * $perpage,
            $perpage
        );
        return array_values($rows);
    }

    /**
     * Search FlexAccess accounts (read-only), joined with user identity.
     *
     * @param string $query Substring matched against e-mail and name.
     * @param string|null $type Optional account-type filter.
     * @param string|null $state Optional account-state filter.
     * @param int $page Zero-based page index.
     * @param int $perpage Page size.
     * @param string|null $reference Optional exact reference-number match.
     * @return array<\stdClass>
     */
    public static function search_accounts(
        string $query = '',
        ?string $type = null,
        ?string $state = null,
        int $page = 0,
        int $perpage = 50,
        ?string $reference = null
    ): array {
        global $DB;
        [$where, $params] = self::build_account_filter($query, $type, $state, $reference);
        $sql = "SELECT a.id, a.userid, a.accounttype, a.accountstate, a.timecreated, a.timeexpires,
                       u.firstname, u.lastname, u.email
                  FROM {auth_flexaccess_account} a
                  JOIN {user} u ON u.id = a.userid
                 WHERE $where
              ORDER BY a.timecreated DESC";
        return array_values($DB->get_records_sql($sql, $params, $page * $perpage, $perpage));
    }

    /**
     * Count FlexAccess accounts matching a filter.
     *
     * @param string $query Substring matched against e-mail and name.
     * @param string|null $type Optional account-type filter.
     * @param string|null $state Optional account-state filter.
     * @param string|null $reference Optional exact reference-number match.
     * @return int
     */
    public static function count_accounts(
        string $query = '',
        ?string $type = null,
        ?string $state = null,
        ?string $reference = null
    ): int {
        global $DB;
        [$where, $params] = self::build_account_filter($query, $type, $state, $reference);
        $sql = "SELECT COUNT(a.id)
                  FROM {auth_flexaccess_account} a
                  JOIN {user} u ON u.id = a.userid
                 WHERE $where";
        return (int) $DB->count_records_sql($sql, $params);
    }

    /**
     * Convert a temporary account administratively and mail the user a set-password link.
     *
     * Capability checks belong to the caller. Requires a real email because the temporary identity
     * carries only a non-deliverable placeholder address.
     *
     * @param int $userid User id.
     * @param string $email Real login email supplied by the administrator.
     * @param string $firstname Optional replacement first name.
     * @param string $lastname Optional replacement last name.
     * @param int|null $now Current time.
     * @return string 'converted' on success, or 'notapplicable'|'invalidemail'|'emailtaken'.
     */
    public static function admin_convert(
        int $userid,
        string $email,
        string $firstname = '',
        string $lastname = '',
        ?int $now = null
    ): string {
        $now = $now ?? time();
        $status = self::finalise_identity($userid, $email, $firstname, $lastname, $now);
        if ($status !== 'ok') {
            return $status;
        }
        // Queue a set-password invitation through the FlexAccess mail queue so it is subject to the
        // same rate limit as every other FlexAccess mail (the token is issued at delivery, never
        // persisted). The account is now permanent, so the link is not capped by an expiry.
        self::queue_token_mail(
            $userid,
            $email,
            mail_kind::SET_PASSWORD,
            'setpassword',
            self::SET_PASSWORD_TTL,
            null,
            $now
        );
        return 'converted';
    }

    /**
     * Look up (without consuming) the user a valid set-password token belongs to.
     *
     * @param string $token Set-password token.
     * @param int|null $now Current time.
     * @return int|null User id, or null when the token is invalid or expired.
     */
    public static function begin_set_password(string $token, ?int $now = null): ?int {
        $record = token_service::verify($token, 'setpassword', $now);
        return $record ? (int) $record->userid : null;
    }

    /**
     * Consume a set-password token and store the chosen password.
     *
     * @param string $token Set-password token.
     * @param string $password Clear-text password chosen by the user.
     * @param int|null $now Current time.
     * @return int|null The user id on success, or null when the token is invalid.
     */
    public static function complete_set_password(string $token, string $password, ?int $now = null): ?int {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/user/lib.php');
        $userid = token_service::consume($token, 'setpassword', $now, null);
        if ($userid === null) {
            return null;
        }
        $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
        update_internal_user_password($user, $password);
        return (int) $userid;
    }

    /**
     * Build the WHERE clause and parameters for an account filter.
     *
     * @param string $query Substring matched against e-mail and name.
     * @param string|null $type Optional account-type filter.
     * @param string|null $state Optional account-state filter.
     * @param string|null $reference Optional exact reference-number match.
     * @return array{0: string, 1: array}
     */
    private static function build_account_filter(
        string $query,
        ?string $type,
        ?string $state,
        ?string $reference = null
    ): array {
        global $DB;
        $where = ['u.deleted = 0'];
        $params = [];
        if ($type !== null && in_array($type, account_type::values(), true)) {
            $where[] = 'a.accounttype = :type';
            $params['type'] = $type;
        }
        if ($state !== null && in_array($state, account_state::values(), true)) {
            $where[] = 'a.accountstate = :state';
            $params['state'] = $state;
        }
        $query = trim($query);
        $reference = $reference !== null ? trim($reference) : '';
        if ($query !== '' || $reference !== '') {
            $clauses = [];
            if ($query !== '') {
                $like = '%' . $DB->sql_like_escape($query) . '%';
                $clauses[] = $DB->sql_like('u.email', ':qe', false);
                $clauses[] = $DB->sql_like('u.firstname', ':qf', false);
                $clauses[] = $DB->sql_like('u.lastname', ':ql', false);
                $params['qe'] = $like;
                $params['qf'] = $like;
                $params['ql'] = $like;
            }
            if ($reference !== '') {
                // Reference numbers are exact identifiers, so match them exactly.
                $clauses[] = 'a.referencecode = :qref';
                $params['qref'] = $reference;
            }
            $where[] = '(' . implode(' OR ', $clauses) . ')';
        }
        return [implode(' AND ', $where), $params];
    }
}
