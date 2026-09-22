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
 * The single server-side decision whether a FlexAccess account may obtain a session right now.
 *
 * Every FlexAccess login path - password, magic link, set-password completion, and the entry flows
 * that log a freshly created account in (temporary access, quick registration, invitation,
 * campaign) - asks this guard, and every direct session creation goes through
 * {@see self::complete_login()}, which re-validates the current state immediately before calling
 * complete_user_login(). A refused login never falls back to a guest session: guest access is only
 * ever started by {@see self::complete_explicit_guest_login()}, an explicit, separate action.
 *
 * The refusal reason is recorded internally (event log); callers show a non-enumerating message.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class login_guard {
    /** Password login (including the session after a completed set-password). */
    public const CHANNEL_PASSWORD = 'password';
    /** Passwordless magic-link login (request and consumption). */
    public const CHANNEL_MAGIC = 'magic';
    /** Session for an account a FlexAccess entry flow has just created (temporary, quick registration). */
    public const CHANNEL_ENTRY = 'entry';

    /** Eligible: no refusal. */
    public const OK = '';
    /** Refusal: the user does not exist. */
    public const REASON_NOUSER = 'nouser';
    /** Refusal: the user is deleted. */
    public const REASON_DELETED = 'deleted';
    /** Refusal: the Moodle user is suspended. */
    public const REASON_SUSPENDED = 'suspended';
    /** Refusal: the Moodle user is not confirmed. */
    public const REASON_UNCONFIRMED = 'unconfirmed';
    /** Refusal: the user is not a FlexAccess user or has no FlexAccess account. */
    public const REASON_NOACCOUNT = 'noaccount';
    /** Refusal: the account is expired (by state or by time). */
    public const REASON_EXPIRED = 'expired';
    /** Refusal: the account is inactive for this channel (e.g. temporary for password login). */
    public const REASON_INACTIVE = 'inactive';
    /** Refusal: the permanent identity still has no usable credential. */
    public const REASON_PENDINGCREDENTIAL = 'pendingcredential';
    /** Refusal: account type and state, or account and core user, contradict each other. */
    public const REASON_STATEMISMATCH = 'statemismatch';

    /**
     * Decide whether the user may obtain a session through the given channel.
     *
     * @param int $userid User id.
     * @param string $channel One of the CHANNEL_* constants.
     * @param int|null $now Current time.
     * @return string {@see self::OK} when eligible, otherwise a REASON_* code.
     */
    public static function evaluate(int $userid, string $channel, ?int $now = null): string {
        global $DB;
        $now = $now ?? time();
        $user = $DB->get_record('user', ['id' => $userid], 'id, auth, deleted, suspended, confirmed');
        if (!$user) {
            return self::REASON_NOUSER;
        }
        if ((int) $user->deleted !== 0) {
            return self::REASON_DELETED;
        }
        if ($user->auth !== 'flexaccess') {
            return self::REASON_NOACCOUNT;
        }
        $account = $DB->get_record('auth_flexaccess_account', ['userid' => $userid]);
        if (!$account) {
            return self::REASON_NOACCOUNT;
        }
        $type = (string) $account->accounttype;
        $state = (string) $account->accountstate;
        if (!lifecycle::expectations($type, $state)->valid) {
            return self::REASON_STATEMISMATCH;
        }
        if (in_array($state, [account_state::EXPIRED, account_state::SUSPENDED], true)) {
            return $state === account_state::EXPIRED ? self::REASON_EXPIRED : self::REASON_SUSPENDED;
        }
        if ((int) $user->suspended !== 0) {
            return self::REASON_SUSPENDED;
        }
        if ((int) $user->confirmed !== 1) {
            return self::REASON_UNCONFIRMED;
        }
        if (!empty($account->timeexpires) && (int) $account->timeexpires <= $now) {
            return self::REASON_EXPIRED;
        }
        if ($state === account_state::PENDING_CREDENTIAL) {
            return self::REASON_PENDINGCREDENTIAL;
        }
        if ($type === account_type::AUTHENTICATED_USER && $state === account_state::ACTIVE) {
            return self::OK;
        }
        // A live temporary account (EPHEMERAL/PROVISIONAL) normally has no reusable credential and may
        // only receive the session its entry flow establishes. The exception is an access-list account:
        // its issued card credential is explicitly meant for password login while the account is valid.
        // A provisional account (quick registration before e-mail verification) is never such an account.
        if ($channel === self::CHANNEL_ENTRY) {
            return self::OK;
        }
        if (
            $channel === self::CHANNEL_PASSWORD
                && $state === account_state::EPHEMERAL
                && (int) ($account->batchcredential ?? 0) === 1
        ) {
            return self::OK;
        }
        return self::REASON_INACTIVE;
    }

    /**
     * Whether the user may obtain a session through the given channel.
     *
     * @param int $userid User id.
     * @param string $channel One of the CHANNEL_* constants.
     * @param int|null $now Current time.
     * @return bool
     */
    public static function is_eligible(int $userid, string $channel, ?int $now = null): bool {
        return self::evaluate($userid, $channel, $now) === self::OK;
    }

    /**
     * Re-validate and, only if eligible, create the authenticated session.
     *
     * This is the only place where FlexAccess calls complete_user_login() for an account. A refusal
     * is logged with its reason and leaves the visitor logged out - never as a guest.
     *
     * @param int $userid User id.
     * @param string $channel One of the CHANNEL_* constants.
     * @param int|null $now Current time.
     * @return bool Whether the session was created.
     */
    public static function complete_login(int $userid, string $channel, ?int $now = null): bool {
        $reason = self::evaluate($userid, $channel, $now);
        if ($reason !== self::OK) {
            self::log_refusal($userid, $channel, $reason);
            return false;
        }
        $user = get_complete_user_data('id', $userid);
        if (!$user) {
            self::log_refusal($userid, $channel, self::REASON_NOUSER);
            return false;
        }
        complete_user_login($user);
        return true;
    }

    /**
     * Start a guest session - only as the explicit alternative the course offers.
     *
     * Kept apart from every account login so that no failed or refused account login can turn into a
     * guest session. The caller must have verified that the visitor pressed the guest button (POST,
     * sesskey); this method additionally re-checks that the course really offers guest access.
     *
     * @param int $courseid Course the guest entry belongs to.
     * @return bool Whether the guest session was created.
     */
    public static function complete_explicit_guest_login(int $courseid): bool {
        if (!self::guest_login_allowed($courseid)) {
            return false;
        }
        $guest = get_complete_user_data('username', 'guest');
        if (!$guest) {
            return false;
        }
        complete_user_login($guest);
        return true;
    }

    /**
     * Whether the course offers the explicit guest alternative at all.
     *
     * @param int $courseid Course id.
     * @return bool
     */
    public static function guest_login_allowed(int $courseid): bool {
        return class_exists(\enrol_flexaccess\api::class) && \enrol_flexaccess\api::offers_guest_access($courseid);
    }

    /**
     * Record a refused login with its precise reason (internal only).
     *
     * @param int $userid User id.
     * @param string $channel Channel.
     * @param string $reason REASON_* code.
     * @return void
     */
    public static function log_refusal(int $userid, string $channel, string $reason): void {
        global $DB;
        $params = [
            'context' => \context_system::instance(),
            'other' => ['channel' => $channel, 'reason' => $reason],
        ];
        // Only reference users that exist; the event log's related user must be a real record.
        if ($userid > 0 && $DB->record_exists('user', ['id' => $userid])) {
            $params['relateduserid'] = $userid;
        }
        \auth_flexaccess\event\login_refused::create($params)->trigger();
    }
}
