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

    /** Cooldown for identical, still-pending token mails to one recipient (per-user rate limit). */
    private const TOKEN_MAIL_COOLDOWN = 300;

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
     * Finalise a temporary account into a permanent identity (email, names, state).
     *
     * @param int $userid User id.
     * @param string $email Verified e-mail address.
     * @param string $firstname First name.
     * @param string $lastname Last name.
     * @param int $now Current time.
     * @param callable|null $inside Optional callback run inside the conversion lock.
     * @return string Status string.
     */
    private static function finalise_identity(
        int $userid,
        string $email,
        string $firstname,
        string $lastname,
        int $now,
        ?callable $inside = null
    ): string {
        return local\persistence_service::finalise_identity($userid, $email, $firstname, $lastname, $now, $inside);
    }

    /**
     * Persist a temporary user immediately (no e-mail verification step).
     *
     * @param int $userid User id.
     * @param string $email E-mail address.
     * @param string $firstname First name.
     * @param string $lastname Last name.
     * @param string $password New password.
     * @param int|null $now Current time.
     * @return string Status string.
     */
    public static function persist_temporary_user(
        int $userid,
        string $email,
        string $firstname,
        string $lastname,
        string $password,
        ?int $now = null
    ): string {
        return local\persistence_service::persist_temporary_user(
            $userid,
            $email,
            $firstname,
            $lastname,
            $password,
            $now
        );
    }

    /**
     * Whether persistence requires e-mail verification on this site.
     *
     * @return bool
     */
    public static function email_verification_required(): bool {
        return local\persistence_service::email_verification_required();
    }

    /**
     * Request persistence of a temporary account (starts verification when required).
     *
     * @param int $userid User id.
     * @param string $email Desired e-mail address.
     * @param string $firstname First name.
     * @param string $lastname Last name.
     * @param string $password Desired password.
     * @param int|null $now Current time.
     * @return string Status string.
     */
    public static function request_persistence(
        int $userid,
        string $email,
        string $firstname,
        string $lastname,
        string $password,
        ?int $now = null
    ): string {
        return local\persistence_service::request_persistence(
            $userid,
            $email,
            $firstname,
            $lastname,
            $password,
            $now
        );
    }

    /**
     * Queue follow-up reminders for pending persistence requests.
     *
     * @param int|null $now Current time.
     * @param int|null $window Window in seconds.
     * @param int $limit Maximum number of follow-ups.
     * @return int Number queued.
     */
    public static function send_persistence_followups(?int $now = null, ?int $window = null, int $limit = 200): int {
        return local\persistence_service::send_persistence_followups($now, $window, $limit);
    }

    /**
     * Confirm a persistence request from its verification token.
     *
     * @param string $token Plain verification token.
     * @param int|null $now Current time.
     * @return string Status string.
     */
    public static function confirm_persistence(string $token, ?int $now = null): string {
        return local\persistence_service::confirm_persistence($token, $now);
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
     * Whether an identical deferred mail is already queued and still waiting to be sent.
     *
     * Lets a component avoid stacking duplicate jobs: two queued invitation mails would not only
     * send twice, they would each mint a token, so the first delivery's link would be invalidated
     * by the second.
     *
     * @param string $renderer Renderer class the job names.
     * @param array $context Non-secret context the job carries.
     * @return bool
     */
    public static function deferred_mail_queued(string $renderer, array $context): bool {
        global $DB;
        $payload = json_encode(['kind' => 'deferred', 'renderer' => $renderer, 'context' => $context]);
        return $DB->record_exists_select(
            self::QUEUE_TABLE,
            'status = :status AND ' . $DB->sql_compare_text('payloadjson') . ' = ' . $DB->sql_compare_text(':payload'),
            ['status' => 'queued', 'payload' => $payload]
        );
    }

    /**
     * Queue a deferred mail unless an identical one is already waiting, atomically.
     *
     * Checking and inserting separately leaves a window in which two parallel requests both pass
     * the check and both insert. Two jobs would not only send twice, they would each mint a token,
     * so the first delivery's link would be killed by the second. The lock closes that window.
     *
     * @param int|null $userid Optional related user id.
     * @param string $recipient Recipient e-mail address.
     * @param string $mailtype Mail type tag for reporting.
     * @param string $renderer Class implementing render_deferred_mail().
     * @param array $context Non-secret context passed back to the renderer.
     * @param int|null $nextrun Earliest delivery time.
     * @param int|null $now Current time.
     * @return int Queue row id, or 0 when an identical job was already queued.
     */
    public static function queue_deferred_mail_once(
        ?int $userid,
        string $recipient,
        string $mailtype,
        string $renderer,
        array $context,
        ?int $nextrun = null,
        ?int $now = null
    ): int {
        $factory = \core\lock\lock_config::get_lock_factory('auth_flexaccess_mailqueue');
        $key = 'deferred_' . sha1($renderer . '|' . json_encode($context));
        $lock = $factory->get_lock($key, 10);
        if (!$lock) {
            // Another request holds the lock, so it is queueing this very mail right now.
            return 0;
        }
        try {
            if (self::deferred_mail_queued($renderer, $context)) {
                return 0;
            }
            return self::queue_deferred_mail($userid, $recipient, $mailtype, $renderer, $context, $nextrun, $now);
        } finally {
            $lock->release();
        }
    }

    /**
     * Queue a mail whose body is rendered by the owning component at delivery time.
     *
     * For mails that carry a one-time secret (invitation links, ...): the queue row holds only a
     * renderer class and a non-secret context, so no token is ever stored at rest, while the mail
     * still passes through the central FlexAccess queue - and therefore the hourly send limit,
     * retry/backoff and queue monitoring.
     *
     * @param int|null $userid Optional related user id.
     * @param string $recipient Recipient e-mail address.
     * @param string $mailtype Mail type tag for reporting.
     * @param string $renderer Class implementing render_deferred_mail() (and optionally
     *     deferred_mail_sent()).
     * @param array $context Non-secret context passed back to the renderer (e.g. a record id).
     * @param int|null $nextrun Earliest delivery time.
     * @param int|null $now Current time.
     * @return int Queue row id.
     */
    public static function queue_deferred_mail(
        ?int $userid,
        string $recipient,
        string $mailtype,
        string $renderer,
        array $context,
        ?int $nextrun = null,
        ?int $now = null
    ): int {
        global $DB;
        $now = $now ?? time();
        return (int) $DB->insert_record(self::QUEUE_TABLE, (object) [
            'userid' => $userid,
            'recipient' => $recipient,
            'mailtype' => $mailtype,
            'payloadjson' => json_encode([
                'kind' => 'deferred',
                'renderer' => $renderer,
                'context' => $context,
            ]),
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
        // P1: per-user/per-recipient rate limit. Without this, a single temporary user could burn a
        // large share of the site's mail budget by repeatedly requesting verification links. An
        // identical, still-pending request within the cooldown window is de-duplicated: the existing
        // queue row is reused (the token is issued at delivery, so the user still gets a valid link).
        $existing = $DB->get_record_select(
            self::QUEUE_TABLE,
            'userid = :userid AND recipient = :recipient AND mailtype = :mailtype
                 AND status = :status AND timecreated > :cutoff',
            [
                'userid' => $userid,
                'recipient' => $recipient,
                'mailtype' => $mailtype,
                'status' => 'queued',
                'cutoff' => $now - self::TOKEN_MAIL_COOLDOWN,
            ],
            'id',
            IGNORE_MULTIPLE
        );
        if ($existing) {
            return (int) $existing->id;
        }
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
     * Whether magic (e-mail link) login is enabled site-wide.
     *
     * @return bool
     */
    public static function magic_login_enabled(): bool {
        return local\magic_service::magic_login_enabled();
    }

    /**
     * Request a magic-login link for an e-mail address.
     *
     * @param string $email E-mail address.
     * @param string|null $clientip Client IP for rate limiting.
     * @param int|null $now Current time.
     * @return string Status string.
     */
    public static function request_magic_login(string $email, ?string $clientip = null, ?int $now = null): string {
        return local\magic_service::request_magic_login($email, $clientip, $now);
    }

    /**
     * Consume a magic-login token and return the user it authenticates.
     *
     * @param string $token Plain magic-login token.
     * @param int|null $now Current time.
     * @return int|null User id, or null when invalid.
     */
    public static function consume_magic_login(string $token, ?int $now = null): ?int {
        return local\magic_service::consume_magic_login($token, $now);
    }


    /**
     * Remove a batch account that was created but could not be fully provisioned.
     *
     * Provisioning a member takes three steps (create account, enrol, record membership). If a later
     * step fails, the account already exists but nothing references it: the resumable batch does not
     * see it, and a retry would create yet another one. This compensating delete keeps each member
     * atomic - either fully recorded or fully gone.
     *
     * Same safety boundary as set_account_password(): it only ever touches a FlexAccess account that
     * still carries the placeholder address, so a personalised account can never be deleted here.
     *
     * @param int $userid Moodle user id.
     * @return bool Whether the account was removed.
     */
    public static function rollback_batch_account(int $userid): bool {
        global $DB;
        $user = $DB->get_record('user', ['id' => $userid], '*', IGNORE_MISSING);
        if (!$user || $user->auth !== 'flexaccess') {
            return false;
        }
        if (!str_ends_with((string) $user->email, '@flexaccess.invalid')) {
            return false;
        }
        // Report the real outcome: if the user could not be deleted, nothing was rolled back.
        return account_service::delete_account($userid);
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
        // Report the real outcome: if the user could not be deleted, nothing was rolled back.
        return account_service::delete_account($userid);
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
        // Hard safety boundary: this must never be a general password reset. It only applies
        // to FlexAccess-managed batch placeholder accounts that have NOT been personalised. Once an
        // account is converted/personalised, finalise_identity() has replaced the placeholder email
        // with the real one, so we refuse - the batch may no longer touch that permanent account.
        if ($user->auth !== 'flexaccess' || !str_ends_with((string) $user->email, '@flexaccess.invalid')) {
            return false;
        }
        return (bool) update_internal_user_password($user, $password);
    }

    /**
     * Aggregate counts of FlexAccess accounts by state.
     *
     * @return array Counts keyed by account state.
     */
    public static function account_stats(): array {
        return local\account_query_service::account_stats();
    }

    /**
     * Aggregate counts of mail queue rows by status.
     *
     * @return array Counts keyed by queue status.
     */
    public static function mailqueue_summary(): array {
        return local\account_query_service::mailqueue_summary();
    }

    /**
     * Count mail queue rows matching a status filter.
     *
     * @param string $status Status filter ('' for all).
     * @return int
     */
    public static function count_mailqueue(string $status = ''): int {
        return local\account_query_service::count_mailqueue($status);
    }

    /**
     * List mail queue rows matching a status filter.
     *
     * @param string $status Optional status filter.
     * @param int $page Zero-based page index.
     * @param int $perpage Page size.
     * @return array<\stdClass>
     */
    public static function list_mailqueue(string $status = '', int $page = 0, int $perpage = 50): array {
        return local\account_query_service::list_mailqueue($status, $page, $perpage);
    }

    /**
     * Search FlexAccess accounts.
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
        return local\account_query_service::search_accounts($query, $type, $state, $page, $perpage, $reference);
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
        return local\account_query_service::count_accounts($query, $type, $state, $reference);
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
        // The user has never seen the generated username; tell them how to log in from now on.
        local\persistence_service::send_welcome($userid, $now);
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
}
