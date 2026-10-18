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
 * Policy and postconditions for merging two Moodle users that involve FlexAccess or LTI identities.
 *
 * Merging means moving every user-owned record of one Moodle user (the source) onto another (the
 * target) - in practice done by tool_mergeusers. FlexAccess owns only its own state, but it decides
 * whether a merge involving its identities may go ahead and whether its result is acceptable:
 *
 * - Direction: the target keeps its auth method and password. A source that is a durable FlexAccess
 *   identity (with its own password) must not be merged into a target that is not a FlexAccess account:
 *   the person would lose the only password login they have. The supported direction is the reverse
 *   (the auth_lti duplicate into the FlexAccess account).
 * - Approval: merging two durable identities (a durable FlexAccess account and an auth_lti account, or
 *   two durable FlexAccess accounts) needs an administrator's prior, recorded confirmation that both
 *   belong to the same person. A shared e-mail address alone is never such a proof.
 * - Postconditions: after the merge no LTI binding (auth_lti_linked_login) and no LTI membership
 *   (enrol_lti_users, which AGS grade delivery and NRPS use) may still point to the source, the target
 *   must not hold two memberships of the same published tool (two grade-delivery targets), and an
 *   administrative suspension of the target must still be in place. Only then is a merge "reconciled".
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class merge_guard {
    /** Refusal: the source is a durable FlexAccess identity and the target is not a FlexAccess account. */
    public const SOURCE_DURABLE = 'sourcedurable';
    /** Refusal: two durable identities without a valid administrator approval. */
    public const NEEDS_APPROVAL = 'needsapproval';
    /** Refusal: the target does not exist (any more). */
    public const TARGET_MISSING = 'targetmissing';

    /** Postcondition: LTI bindings still point to the source. */
    public const SOURCE_BINDINGS = 'sourcebindings';
    /** Postcondition: LTI memberships (grade delivery) still point to the source. */
    public const SOURCE_LTI_USERS = 'sourceltiusers';
    /** Postcondition: the target holds two memberships of the same published tool. */
    public const DUPLICATE_LTI_USERS = 'duplicateltiusers';
    /** Postcondition: the target is suspended by someone other than FlexAccess. */
    public const TARGET_LOCKED = 'targetlocked';
    /** Postcondition: the administrative suspension of the target was lifted during the merge. */
    public const TARGET_LOCK_LIFTED = 'targetlocklifted';

    /** Postconditions that must hold inside the merge transaction (otherwise the merge is rolled back). */
    public const BLOCKING_POSTCONDITIONS = [self::SOURCE_BINDINGS, self::SOURCE_LTI_USERS, self::DUPLICATE_LTI_USERS];

    /**
     * Facts about one identity that matter for a merge.
     *
     * @param int $userid User id.
     * @return \stdClass ->exists, ->auth, ->suspended, ->accounttype, ->accountstate, ->lockedby,
     *     ->haspassword, ->durable (FlexAccess account with its own password), ->bindings, ->ltiusers,
     *     ->grades, ->enrolments.
     */
    public static function identity(int $userid): \stdClass {
        global $DB;
        $user = $DB->get_record('user', ['id' => $userid], 'id, auth, suspended, deleted, password, email');
        $account = $DB->get_record('auth_flexaccess_account', ['userid' => $userid]);
        $hash = $user ? (string) $user->password : '';
        $haspassword = $hash !== '' && $hash !== AUTH_PASSWORD_NOT_CACHED;
        return (object) [
            'userid' => $userid,
            'exists' => $user && (int) $user->deleted === 0,
            'auth' => $user ? (string) $user->auth : '',
            'email' => $user ? (string) $user->email : '',
            'suspended' => $user ? (int) $user->suspended : 0,
            'accounttype' => $account ? (string) $account->accounttype : '',
            'accountstate' => $account ? (string) $account->accountstate : '',
            'lockedby' => $account ? ($account->lockedby ?? null) : null,
            'haspassword' => $haspassword,
            'durable' => $account && (string) $account->accounttype === account_type::AUTHENTICATED_USER && $haspassword,
            'bindings' => $DB->count_records('auth_lti_linked_login', ['userid' => $userid]),
            'ltiusers' => self::table_exists('enrol_lti_users') ? $DB->count_records('enrol_lti_users', ['userid' => $userid]) : 0,
            'grades' => $DB->count_records_select('grade_grades', 'userid = :u AND finalgrade IS NOT NULL', ['u' => $userid]),
            'enrolments' => $DB->count_records('user_enrolments', ['userid' => $userid]),
        ];
    }

    /**
     * Whether merging this pair needs an administrator's approval (two durable identities).
     *
     * @param \stdClass $from Source facts.
     * @param \stdClass $to Target facts.
     * @return bool
     */
    public static function needs_approval(\stdClass $from, \stdClass $to): bool {
        // Judged by what a generic table merge does not move (auth method, FlexAccess account row), so the
        // verdict inside tool_mergeusers' transaction equals the one before the merge.
        $fromdurable = $from->durable || $from->auth === 'lti';
        $todurable = $to->durable || $to->auth === 'lti';
        $flexaccess = $from->accounttype !== '' || $to->accounttype !== '';
        return $flexaccess && $fromdurable && $todurable;
    }

    /**
     * Assess a merge before it is done (read-only).
     *
     * @param int $fromuserid Identity merged away.
     * @param int $touserid Surviving identity.
     * @param int|null $now Current time.
     * @return \stdClass ->from, ->to (see {@see self::identity()}), ->blockers (string[]), ->warnings (string[]),
     *     ->needsapproval (bool), ->approved (bool).
     */
    public static function assess(int $fromuserid, int $touserid, ?int $now = null): \stdClass {
        $from = self::identity($fromuserid);
        $to = self::identity($touserid);
        $blockers = [];
        $warnings = [];
        if (!$to->exists) {
            $blockers[] = self::TARGET_MISSING;
        }
        if ($from->durable && ($to->auth !== 'flexaccess' || !$to->durable)) {
            // The target keeps its auth method and its (missing) password: the person would lose their only
            // password login - a non-FlexAccess target, or a FlexAccess target without an own credential
            // (temporary, or still waiting for its password).
            $blockers[] = self::SOURCE_DURABLE;
        }
        $needsapproval = self::needs_approval($from, $to);
        $approved = $needsapproval && merge_registry::is_approved($fromuserid, $touserid, $now);
        if ($needsapproval && !$approved) {
            $blockers[] = self::NEEDS_APPROVAL;
        }
        if ($to->suspended && !lifecycle::suspension_liftable($touserid)) {
            $warnings[] = self::TARGET_LOCKED;
        }
        if (self::shared_tools($fromuserid, $touserid)) {
            $warnings[] = self::DUPLICATE_LTI_USERS;
        }
        return (object) [
            'from' => $from,
            'to' => $to,
            'blockers' => $blockers,
            'warnings' => $warnings,
            'needsapproval' => $needsapproval,
            'approved' => $approved,
        ];
    }

    /**
     * Unmet postconditions of a merge as the data stands now.
     *
     * @param int $fromuserid Identity merged away.
     * @param int $touserid Surviving identity.
     * @param \stdClass|null $registry Registry row (for the target's suspension before the merge).
     * @return string[] Unmet postconditions; empty when the merge is complete.
     */
    public static function postconditions(int $fromuserid, int $touserid, ?\stdClass $registry = null): array {
        global $DB;
        $problems = [];
        if ($DB->record_exists('auth_lti_linked_login', ['userid' => $fromuserid])) {
            $problems[] = self::SOURCE_BINDINGS;
        }
        if (self::table_exists('enrol_lti_users')) {
            if ($DB->record_exists('enrol_lti_users', ['userid' => $fromuserid])) {
                $problems[] = self::SOURCE_LTI_USERS;
            }
            $duplicates = $DB->count_records_sql(
                "SELECT COUNT(1) FROM (
                    SELECT toolid FROM {enrol_lti_users} WHERE userid = :userid GROUP BY toolid HAVING COUNT(1) > 1
                 ) dup",
                ['userid' => $touserid]
            );
            if ($duplicates > 0) {
                $problems[] = self::DUPLICATE_LTI_USERS;
            }
        }
        $to = self::identity($touserid);
        if ($to->suspended && !lifecycle::suspension_liftable($touserid)) {
            $problems[] = self::TARGET_LOCKED;
        }
        if (
            $registry && (int) $registry->targetsuspended === 1 && !$to->suspended
                && $registry->targetlockedby !== lifecycle::LOCKED_BY_FLEXACCESS
        ) {
            $problems[] = self::TARGET_LOCK_LIFTED;
        }
        return $problems;
    }

    /**
     * Whether source and target are both members of the same published LTI tool.
     *
     * @param int $fromuserid Source.
     * @param int $touserid Target.
     * @return bool
     */
    private static function shared_tools(int $fromuserid, int $touserid): bool {
        global $DB;
        if (!self::table_exists('enrol_lti_users')) {
            return false;
        }
        return $DB->record_exists_sql(
            "SELECT 1
               FROM {enrol_lti_users} a
               JOIN {enrol_lti_users} b ON b.toolid = a.toolid AND b.userid = :to
              WHERE a.userid = :from",
            ['from' => $fromuserid, 'to' => $touserid]
        );
    }

    /**
     * Whether a table exists (enrol_lti may be uninstalled on some sites).
     *
     * @param string $table Table name without prefix.
     * @return bool
     */
    private static function table_exists(string $table): bool {
        global $DB;
        static $known = [];
        return $known[$table] ??= $DB->get_manager()->table_exists($table);
    }
}
