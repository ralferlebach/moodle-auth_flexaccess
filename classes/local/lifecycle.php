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
 * The single owner of FlexAccess account lifecycle transitions and their invariants.
 *
 * Every lifecycle state implies a complete target state across four stores:
 *
 * | account type / state               | user.suspended | restriction role |
 * |------------------------------------|----------------|------------------|
 * | temporary, EPHEMERAL / PROVISIONAL | 0              | assigned         |
 * | temporary, EXPIRED / SUSPENDED     | 1              | assigned         |
 * | authenticated, PENDING_CREDENTIAL  | 0              | assigned         |
 * | authenticated, ACTIVE              | 0              | not assigned     |
 * | authenticated, EXPIRED / SUSPENDED | 1              | any              |
 *
 * A transition always writes the whole target state, so no caller has to repair individual core
 * fields afterwards. For users of the FlexAccess auth method, user.suspended is derived from the
 * FlexAccess state; an administrative block is modelled as the FlexAccess state SUSPENDED, never as a
 * bare user.suspended flag that would contradict the account state. Course enrolment is a separate
 * lifecycle owned by enrol_flexaccess; it is diagnosed alongside, but never changed here.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class lifecycle {
    /** Account table. */
    private const TABLE = 'auth_flexaccess_account';

    /** Mismatch: ACTIVE account whose Moodle user is suspended. */
    public const MISMATCH_ACTIVE_SUSPENDED = 'active_suspended';
    /** Mismatch: ACTIVE account that still holds the restriction role. */
    public const MISMATCH_ACTIVE_RESTRICTED = 'active_restricted';
    /** Mismatch: live temporary or pending account without the restriction role. */
    public const MISMATCH_TEMPORARY_UNRESTRICTED = 'temporary_unrestricted';
    /** Mismatch: EXPIRED/SUSPENDED account whose Moodle user is not suspended. */
    public const MISMATCH_LOCKED_UNSUSPENDED = 'locked_unsuspended';
    /** Mismatch: live temporary or pending account whose Moodle user is suspended. */
    public const MISMATCH_LIVE_SUSPENDED = 'live_suspended';
    /** Mismatch: temporary account past its expiry that has not been expired yet. */
    public const MISMATCH_OVERDUE = 'overdue';
    /** Mismatch: account type and state contradict each other. */
    public const MISMATCH_TYPE_STATE = 'type_state';
    /** Mismatch: a user holds the restriction role without a FlexAccess account that needs it. */
    public const MISMATCH_ORPHAN_RESTRICTION = 'orphan_restriction';

    /** Mismatches a deterministic, explicit repair exists for. */
    public const REPAIRABLE = [
        self::MISMATCH_ACTIVE_RESTRICTED,
        self::MISMATCH_TEMPORARY_UNRESTRICTED,
        self::MISMATCH_LOCKED_UNSUSPENDED,
        self::MISMATCH_ORPHAN_RESTRICTION,
    ];

    /**
     * Expected core/role state for an account type and state.
     *
     * @param string $type Account type.
     * @param string $state Account state.
     * @return \stdClass ->suspended (0|1), ->restricted (bool|null = either is fine), ->valid (bool).
     */
    public static function expectations(string $type, string $state): \stdClass {
        $locked = in_array($state, [account_state::EXPIRED, account_state::SUSPENDED], true);
        if ($type === account_type::TEMPORARY_USER) {
            $valid = in_array($state, [
                account_state::EPHEMERAL,
                account_state::PROVISIONAL,
                account_state::EXPIRED,
                account_state::SUSPENDED,
            ], true);
            return (object) ['suspended' => $locked ? 1 : 0, 'restricted' => true, 'valid' => $valid];
        }
        if ($state === account_state::PENDING_CREDENTIAL) {
            return (object) ['suspended' => 0, 'restricted' => true, 'valid' => true];
        }
        if ($state === account_state::ACTIVE) {
            return (object) ['suspended' => 0, 'restricted' => false, 'valid' => true];
        }
        return (object) ['suspended' => $locked ? 1 : 0, 'restricted' => null, 'valid' => $locked];
    }

    /**
     * Make an account a permanent, usable identity: authenticated + ACTIVE.
     *
     * Sets the complete target state: no expiry, confirmed and unsuspended Moodle user, restriction
     * role lifted, pending-credential marker cleared. Enrolments are intentionally left alone.
     *
     * @param int $userid User id.
     * @param int|null $now Current time.
     * @return bool False when the user has no FlexAccess account.
     */
    public static function transition_to_active_authenticated(int $userid, ?int $now = null): bool {
        global $DB;
        $now = $now ?? time();
        $account = $DB->get_record(self::TABLE, ['userid' => $userid]);
        if (!$account) {
            return false;
        }
        $account->accounttype = account_type::AUTHENTICATED_USER;
        $account->accountstate = account_state::ACTIVE;
        // A permanent identity is governed by the authenticated lifecycle only.
        $account->batchcredential = 0;
        $account->timeexpires = null;
        if (empty($account->timeactivated)) {
            $account->timeactivated = $now;
        }
        $account->timemodified = $now;
        $DB->update_record(self::TABLE, $account);
        self::set_core_flags($userid, 0, 1);
        self::set_restricted($userid, false);
        unset_user_preference('auth_flexaccess_pendingcredential', $userid);
        return true;
    }

    /**
     * Make an account a permanent identity that still lacks a usable credential.
     *
     * Used by the administrative conversion: the identity is permanent (no expiry, real e-mail), but
     * the account is not loginnable until the user has set a password. The Moodle user must stay
     * unsuspended so that the set-password mail can be delivered at all.
     *
     * @param int $userid User id.
     * @param int|null $now Current time.
     * @return bool False when the user has no FlexAccess account.
     */
    public static function transition_to_pending_credential(int $userid, ?int $now = null): bool {
        global $DB;
        $now = $now ?? time();
        $account = $DB->get_record(self::TABLE, ['userid' => $userid]);
        if (!$account) {
            return false;
        }
        $account->accounttype = account_type::AUTHENTICATED_USER;
        $account->accountstate = account_state::PENDING_CREDENTIAL;
        $account->batchcredential = 0;
        $account->timeexpires = null;
        $account->timemodified = $now;
        $DB->update_record(self::TABLE, $account);
        self::set_core_flags($userid, 0, 1);
        self::set_restricted($userid, true);
        set_user_preference('auth_flexaccess_pendingcredential', $now, $userid);
        return true;
    }

    /**
     * Expire a temporary account: EXPIRED, Moodle user suspended, running sessions ended.
     *
     * @param int $userid User id.
     * @param int|null $now Current time.
     * @return bool False when there is no temporary account to expire.
     */
    public static function transition_to_expired(int $userid, ?int $now = null): bool {
        global $DB;
        $now = $now ?? time();
        $account = $DB->get_record(self::TABLE, ['userid' => $userid]);
        if (!$account || $account->accounttype !== account_type::TEMPORARY_USER) {
            return false;
        }
        $account->accountstate = account_state::EXPIRED;
        $account->timemodified = $now;
        $DB->update_record(self::TABLE, $account);
        self::set_core_flags($userid, 1, null);
        self::end_sessions($userid);
        return true;
    }

    /**
     * Revive an expired temporary account into a new, time-limited live state.
     *
     * An account that was waiting for e-mail verification (a pending address exists) goes back to
     * PROVISIONAL; it only becomes permanent once the new verification link is followed, so the
     * verification is never bypassed. An anonymous account goes back to EPHEMERAL. Either way the
     * account receives a fresh, bounded lifetime and the Moodle user is unsuspended.
     *
     * @param int $userid User id.
     * @param int $lifetime Seconds the recovered account stays valid (> 0).
     * @param int|null $now Current time.
     * @return string|null The new state, or null when the account is not a temporary account.
     */
    public static function transition_to_recovered_temporary(int $userid, int $lifetime, ?int $now = null): ?string {
        global $DB;
        $now = $now ?? time();
        $account = $DB->get_record(self::TABLE, ['userid' => $userid]);
        if (!$account || $account->accounttype !== account_type::TEMPORARY_USER) {
            return null;
        }
        $pending = get_user_preferences('auth_flexaccess_pendingemail', null, $userid);
        $state = ($pending !== null && $pending !== '') ? account_state::PROVISIONAL : account_state::EPHEMERAL;
        $account->accountstate = $state;
        $account->timeexpires = $now + max(1, $lifetime);
        $account->timemodified = $now;
        $DB->update_record(self::TABLE, $account);
        self::set_core_flags($userid, 0, null);
        self::set_restricted($userid, true);
        // The one-time follow-up reminder belongs to the previous lifetime.
        unset_user_preference('auth_flexaccess_followupsent', $userid);
        return $state;
    }

    /**
     * Bring the core flags and the restriction role of an account in line with its current state.
     *
     * Only the expectations of the lifecycle table are applied; the FlexAccess state itself is never
     * changed. This is the repair for ACTIVE accounts carrying a stale suspension or restriction.
     *
     * @param int $userid User id.
     * @return bool False when the user has no FlexAccess account or the state is contradictory.
     */
    public static function normalise(int $userid): bool {
        global $DB;
        $account = $DB->get_record(self::TABLE, ['userid' => $userid]);
        if (!$account) {
            return false;
        }
        $expect = self::expectations((string) $account->accounttype, (string) $account->accountstate);
        if (!$expect->valid) {
            return false;
        }
        self::set_core_flags($userid, (int) $expect->suspended, null);
        if ($expect->restricted !== null) {
            self::set_restricted($userid, (bool) $expect->restricted);
        }
        if ($expect->suspended === 1) {
            self::end_sessions($userid);
        }
        return true;
    }

    /**
     * Read-only diagnosis: find accounts whose stores contradict the lifecycle invariants.
     *
     * @param int|null $now Current time.
     * @param int $limit Maximum number of mismatches to return.
     * @param int[]|null $userids Optional restriction to these users.
     * @return \stdClass[] Each with ->userid, ->code, ->accounttype, ->accountstate, ->suspended, ->restricted.
     */
    public static function find_state_mismatches(?int $now = null, int $limit = 500, ?array $userids = null): array {
        global $DB;
        $now = $now ?? time();
        $restrictionid = self::restriction_role_id();
        $systemid = \context_system::instance()->id;

        $where = 'u.deleted = 0';
        $params = ['rid' => $restrictionid, 'ctx' => $systemid];
        if ($userids !== null) {
            if (!$userids) {
                return [];
            }
            [$insql, $inparams] = $DB->get_in_or_equal(array_map('intval', $userids), SQL_PARAMS_NAMED, 'uid');
            $where .= " AND a.userid $insql";
            $params += $inparams;
        }
        $sql = "SELECT a.userid, a.accounttype, a.accountstate, a.timeexpires, u.suspended,
                       CASE WHEN ra.id IS NULL THEN 0 ELSE 1 END AS restricted
                  FROM {" . self::TABLE . "} a
                  JOIN {user} u ON u.id = a.userid
             LEFT JOIN {role_assignments} ra ON ra.userid = a.userid AND ra.roleid = :rid AND ra.contextid = :ctx
                 WHERE $where
              ORDER BY a.userid ASC";
        $found = [];
        $rs = $DB->get_recordset_sql($sql, $params);
        foreach ($rs as $row) {
            foreach (self::classify($row, $now, $restrictionid > 0) as $code) {
                $found[] = self::mismatch($row, $code);
                if (count($found) >= $limit) {
                    break 2;
                }
            }
        }
        $rs->close();

        // Users carrying the restriction role although no FlexAccess account needs it (for example
        // after an external identity merge that moved role assignments by direct table updates).
        if ($restrictionid > 0 && count($found) < $limit && $userids === null) {
            $sql = "SELECT ra.userid
                      FROM {role_assignments} ra
                      JOIN {user} u ON u.id = ra.userid AND u.deleted = 0
                 LEFT JOIN {" . self::TABLE . "} a ON a.userid = ra.userid
                     WHERE ra.roleid = :rid AND ra.contextid = :ctx AND a.id IS NULL";
            $orphans = $DB->get_fieldset_sql($sql, ['rid' => $restrictionid, 'ctx' => $systemid]);
            foreach ($orphans as $userid) {
                $found[] = (object) [
                    'userid' => (int) $userid,
                    'code' => self::MISMATCH_ORPHAN_RESTRICTION,
                    'accounttype' => '',
                    'accountstate' => '',
                    'suspended' => null,
                    'restricted' => 1,
                ];
                if (count($found) >= $limit) {
                    break;
                }
            }
        }
        return $found;
    }

    /**
     * Explicitly repair one detected mismatch. Only deterministic repairs are offered.
     *
     * @param int $userid User id.
     * @param string $code Mismatch code (one of {@see self::REPAIRABLE}).
     * @return bool Whether the mismatch was present and has been repaired.
     */
    public static function repair(int $userid, string $code): bool {
        if (!in_array($code, self::REPAIRABLE, true)) {
            return false;
        }
        if ($code === self::MISMATCH_ORPHAN_RESTRICTION) {
            if (\auth_flexaccess\api::get_account($userid) !== null) {
                return false;
            }
            self::set_restricted($userid, false);
            return true;
        }
        $still = array_filter(
            self::find_state_mismatches(null, 50, [$userid]),
            static fn(\stdClass $m): bool => $m->code === $code
        );
        if (!$still) {
            return false;
        }
        return self::normalise($userid);
    }

    /**
     * Classify one joined account row against the invariants.
     *
     * @param \stdClass $row Row with accounttype, accountstate, timeexpires, suspended, restricted.
     * @param int $now Current time.
     * @param bool $rolemodel Whether the restriction role exists (role checks are skipped otherwise).
     * @return string[] Mismatch codes.
     */
    private static function classify(\stdClass $row, int $now, bool $rolemodel): array {
        $type = (string) $row->accounttype;
        $state = (string) $row->accountstate;
        $expect = self::expectations($type, $state);
        if (!$expect->valid) {
            return [self::MISMATCH_TYPE_STATE];
        }
        $codes = [];
        $suspended = (int) $row->suspended;
        $restricted = (int) $row->restricted === 1;
        if ($suspended !== (int) $expect->suspended) {
            if ($state === account_state::ACTIVE) {
                $codes[] = self::MISMATCH_ACTIVE_SUSPENDED;
            } else if ($expect->suspended === 1) {
                $codes[] = self::MISMATCH_LOCKED_UNSUSPENDED;
            } else {
                $codes[] = self::MISMATCH_LIVE_SUSPENDED;
            }
        }
        if ($rolemodel && $expect->restricted !== null && $restricted !== $expect->restricted) {
            $codes[] = $expect->restricted ? self::MISMATCH_TEMPORARY_UNRESTRICTED : self::MISMATCH_ACTIVE_RESTRICTED;
        }
        if (
            $type === account_type::TEMPORARY_USER
                && in_array($state, [account_state::EPHEMERAL, account_state::PROVISIONAL], true)
                && !empty($row->timeexpires) && (int) $row->timeexpires <= $now
        ) {
            $codes[] = self::MISMATCH_OVERDUE;
        }
        return $codes;
    }

    /**
     * Build a mismatch record.
     *
     * @param \stdClass $row Joined row.
     * @param string $code Mismatch code.
     * @return \stdClass
     */
    private static function mismatch(\stdClass $row, string $code): \stdClass {
        return (object) [
            'userid' => (int) $row->userid,
            'code' => $code,
            'accounttype' => (string) $row->accounttype,
            'accountstate' => (string) $row->accountstate,
            'suspended' => (int) $row->suspended,
            'restricted' => (int) $row->restricted,
        ];
    }

    /**
     * Id of the site-wide restriction role (owned by enrol_flexaccess), or 0 when unavailable.
     *
     * @return int
     */
    private static function restriction_role_id(): int {
        if (!class_exists('\enrol_flexaccess\local\participant_role')) {
            return 0;
        }
        return \enrol_flexaccess\local\participant_role::get_restriction_id();
    }

    /**
     * Assign or lift the site-wide restriction role through its owning plugin.
     *
     * Guarded so that auth keeps working (and its tests run) without the enrol sibling.
     *
     * @param int $userid User id.
     * @param bool $restricted Whether the user should hold the restriction role.
     * @return void
     */
    private static function set_restricted(int $userid, bool $restricted): void {
        if (!class_exists('\enrol_flexaccess\local\participant_role')) {
            return;
        }
        if ($restricted) {
            \enrol_flexaccess\local\participant_role::restrict($userid);
        } else {
            \enrol_flexaccess\local\participant_role::unrestrict($userid);
        }
    }

    /**
     * Write the suspended/confirmed flags of the Moodle user when they differ, via the user API.
     *
     * @param int $userid User id.
     * @param int $suspended Target suspended flag.
     * @param int|null $confirmed Target confirmed flag, or null to leave it unchanged.
     * @return void
     */
    private static function set_core_flags(int $userid, int $suspended, ?int $confirmed): void {
        global $CFG, $DB;
        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], 'id, suspended, confirmed');
        if (!$user) {
            return;
        }
        $changes = ['id' => $userid];
        if ((int) $user->suspended !== $suspended) {
            $changes['suspended'] = $suspended;
        }
        if ($confirmed !== null && (int) $user->confirmed !== $confirmed) {
            $changes['confirmed'] = $confirmed;
        }
        if (count($changes) === 1) {
            return;
        }
        require_once($CFG->dirroot . '/user/lib.php');
        if (method_exists(\core\user::class, 'update_user')) {
            \core\user::update_user((object) $changes, false, true);
        } else {
            user_update_user((object) $changes, false, true);
        }
    }

    /**
     * End all running sessions of a user that has just been locked.
     *
     * @param int $userid User id.
     * @return void
     */
    private static function end_sessions(int $userid): void {
        if (method_exists(\core\session\manager::class, 'destroy_user_sessions')) {
            \core\session\manager::destroy_user_sessions($userid);
        } else {
            \core\session\manager::kill_user_sessions($userid);
        }
    }
}
