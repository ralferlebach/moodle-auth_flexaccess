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
 * Persistence lifecycle: turning a temporary account into a permanent, verified identity.
 *
 * Split out of the api facade so this part of the identity lifecycle can be reviewed on its own.
 * The facade keeps its signatures and delegates here.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class persistence_service {
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
    public static function finalise_identity(
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
            if (!\auth_flexaccess\api::email_available($email, $userid)) {
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
     * Send the post-persistence welcome mail: greeting, username and where to log in.
     *
     * A persisted account is a fully-fledged Moodle account, but the user has never been told its
     * username - the temporary one was generated. Without this mail they cannot log in again, and
     * cannot use password recovery either (that needs the address, which they do know, but they
     * have no idea which account it belongs to). The password is deliberately NOT included: the
     * user chose it themselves, and if they forget it the standard email recovery applies.
     *
     * Queued through the central FlexAccess mail queue, so the hourly limit and retries apply. The
     * body carries no secret, so a plain queued mail is appropriate here.
     *
     * @param int $userid User id of the now-permanent account.
     * @param int|null $now Current time.
     * @return void
     */
    public static function send_welcome(int $userid, ?int $now = null): void {
        global $DB, $CFG;
        $now = $now ?? time();
        $user = $DB->get_record('user', ['id' => $userid], 'id, username, firstname, lastname, email', IGNORE_MISSING);
        if (!$user || empty($user->email) || \core_text::strpos($user->email, '@flexaccess.invalid') !== false) {
            // No usable address (still a placeholder): nothing to send.
            return;
        }
        $data = (object) [
            'firstname' => $user->firstname,
            'username' => $user->username,
            'siteurl' => $CFG->wwwroot,
            'loginurl' => $CFG->wwwroot . '/login/index.php',
            'forgoturl' => $CFG->wwwroot . '/login/forgot_password.php',
        ];
        $subject = get_string('welcomesubject', 'auth_flexaccess', $data);
        $body = get_string('welcomebody', 'auth_flexaccess', $data);
        $bodyhtml = \html_writer::tag('p', nl2br(s($body)));
        \auth_flexaccess\api::queue_mail($userid, $user->email, $subject, $body, $bodyhtml, 'welcome', $now);
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
            self::send_welcome($userid, $now);
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
        if (!\auth_flexaccess\api::email_available($email, $userid)) {
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
        $account = \auth_flexaccess\api::get_account($userid);
        if ($account && $account->timeexpires !== null) {
            $ttl = min($ttl, max(1, (int) $account->timeexpires - $now));
        }
        \auth_flexaccess\api::queue_token_mail($userid, $email, mail_kind::VERIFICATION, 'persistence', $ttl, null, $now);
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
            \auth_flexaccess\api::queue_token_mail(
                $userid,
                (string) $pending,
                mail_kind::VERIFICATION,
                'persistence',
                $ttl,
                null,
                $now
            );
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
            self::send_welcome($userid, $now);
            return 'converted';
        }
        // Map the central guard's vocabulary onto this endpoint's.
        return $status === 'notapplicable' ? 'expired' : $status;
    }
}
