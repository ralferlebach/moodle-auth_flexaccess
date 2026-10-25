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
 * - Direction: the origin-type matrix of {@see merge_policy} (temporary, provisional and durable FlexAccess
 *   accounts, local password accounts, auth_lti accounts and institutionally managed accounts). The
 *   target keeps its auth method and password; a source with its own password may only be absorbed by a
 *   survivor with an interactive login of its own, a managed identity is never merged away into a
 *   non-managed one, and temporary or provisional identities never survive a durable one.
 * - Approval: merging two durable identities, or any merge with conflicting course work, needs an
 *   administrator's prior, recorded confirmation with an ownership proof that both belong to the same
 *   person. A shared e-mail address alone is never such a proof.
 * - Scope: pairs without any FlexAccess state (no account row, no FlexAccess enrolment) are left to the
 *   site's general merge tool; FlexAccess neither blocks nor records them.
 * - Postconditions: after the merge no LTI binding (auth_lti_linked_login) and no LTI membership
 *   (enrol_lti_users, which AGS grade delivery and NRPS use) may still point to the source, the target
 *   must not hold two memberships of the same published tool (two grade-delivery targets), and an
 *   administrative suspension of the target must still be in place. Course work must not remain split
 *   on the source, and captured conflict evidence must have been reviewed. Only then is a merge
 *   "reconciled".
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class merge_guard {
    /** Refusal: the source has its own password, the survivor no interactive login of its own (LTI, temporary). */
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

    /** Postcondition: course work (grades, attempts, submissions, completion, enrolments) remains on the source. */
    public const SOURCE_USERDATA = 'sourceuserdata';
    /** Postcondition (and warning): conflicting course work was captured and still awaits review. */
    public const CONFLICTS_RECORDED = 'conflictsrecorded';

    /** Tables whose rows of the source must have moved to the survivor after a merge. */
    public const USERDATA_TABLES = [
        'grade_grades',
        'quiz_attempts',
        'assign_submission',
        'course_completions',
        'course_modules_completion',
        'groups_members',
        'badge_issued',
        'competency_usercomp',
        'user_enrolments',
    ];

    /** Postcondition: a recorded FlexAccess enrolment of the source could not be merged into the survivor's. */
    public const ENROLMENTS_PENDING = 'enrolmentspending';

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
        // Judged by what a generic table merge does not move (auth method, password, FlexAccess account
        // row), so the verdict inside tool_mergeusers' transaction equals the one before the merge.
        return in_array(merge_policy::origin($from), merge_policy::DURABLE, true)
            && in_array(merge_policy::origin($to), merge_policy::DURABLE, true);
    }

    /**
     * Assess a merge before it is done (read-only).
     *
     * @param int $fromuserid Identity merged away.
     * @param int $touserid Surviving identity.
     * @param int|null $now Current time.
     * @return \stdClass ->from, ->to (see {@see self::identity()}), ->origins (from, to), ->involved (bool: FlexAccess
     *     has a stake), ->blockers (string[]), ->warnings (string[]), ->needsapproval (bool), ->approved (bool),
     *     ->conflicts (table => shared keys, before the merge), ->evidence (int: captured conflict records),
     *     ->keptroute (survivor's login route), ->lostroutes (login routes of the source that end), ->sso
     *     (institutional binding of the survivor, see {@see merge_policy::sso_binding()}, or null).
     */
    public static function assess(int $fromuserid, int $touserid, ?int $now = null): \stdClass {
        $from = self::identity($fromuserid);
        $to = self::identity($touserid);
        // A reconciled merge removed the source's FlexAccess row: its origin is the one recorded inside the
        // merge, so that re-checks judge the pair as it was (auth_flexaccess #13).
        $registry = merge_registry::get($fromuserid, $touserid);
        $recorded = $registry ? (json_decode((string) $registry->details, true)['origins']['from'] ?? null) : null;
        $sourceorigin = is_string($recorded) && !$from->accounttype ? $recorded : merge_policy::origin($from);
        $result = (object) [
            'from' => $from,
            'to' => $to,
            'origins' => ['from' => $sourceorigin, 'to' => merge_policy::origin($to)],
            'involved' => merge_policy::involved($fromuserid, $touserid),
            'blockers' => [],
            'warnings' => [],
            'needsapproval' => false,
            'approved' => false,
            'conflicts' => [],
            'evidence' => 0,
            'keptroute' => merge_policy::login_route(merge_policy::origin($to)),
            'lostroutes' => [],
            'sso' => null,
        ];
        $sourceroute = merge_policy::login_route($result->origins['from']);
        if (!in_array($sourceroute, ['none', 'ltilaunch'], true) && $sourceroute !== $result->keptroute) {
            // LTI bindings move with the merge; every other login route of the source ends with it.
            $result->lostroutes[] = $sourceroute;
        }
        if (!$result->involved) {
            // No FlexAccess state on either side: the site's general merge tool decides alone.
            return $result;
        }
        $result->blockers = array_merge(
            merge_policy::direction_blockers($from, $to, $sourceorigin),
            merge_policy::chain_blockers($fromuserid, $touserid)
        );
        if (merge_coverage::gaps()) {
            // A table merger FlexAccess cannot wrap: its collisions would not be archived (fail closed).
            $result->blockers[] = merge_policy::MERGER_COVERAGE;
        }
        if ($result->origins['to'] === merge_policy::INSTITUTIONAL) {
            // The kept institutional login must be one FlexAccess can verify, and unchanged since the approval.
            $result->sso = merge_policy::sso_binding($touserid);
            if (!$result->sso->supported) {
                $result->blockers[] = merge_policy::SSO_UNSUPPORTED;
            } else if (!$result->sso->verified) {
                $result->blockers[] = merge_policy::SSO_UNVERIFIED;
            }
            $approved = $registry && !empty($registry->ssobinding) ? json_decode((string) $registry->ssobinding, true) : null;
            if (is_array($approved) && !merge_policy::sso_binding_holds($approved, $result->sso->binding)) {
                $result->blockers[] = merge_policy::SSO_CHANGED;
            }
        }
        $result->conflicts = merge_policy::conflicts($fromuserid, $touserid);
        $result->evidence = count(merge_evidence::for_pair($fromuserid, $touserid));
        $hasconflicts = $result->conflicts || $result->evidence > 0;
        // Every merge FlexAccess has a stake in needs an administrator's approval with an ownership proof,
        // temporary and provisional sources included (auth_flexaccess #13): holding a temporary session or link
        // is no proof of owning another account. There is no automatic exception; making a temporary access
        // permanent happens in place (persistence), without a merge.
        $result->needsapproval = true;
        $result->approved = merge_registry::is_approved($fromuserid, $touserid, $now);
        if ($result->needsapproval && !$result->approved) {
            $result->blockers[] = self::NEEDS_APPROVAL;
        }
        if ($to->suspended && !lifecycle::suspension_liftable($touserid)) {
            $result->warnings[] = self::TARGET_LOCKED;
        }
        if (self::shared_tools($fromuserid, $touserid)) {
            $result->warnings[] = self::DUPLICATE_LTI_USERS;
        }
        if ($hasconflicts) {
            $result->warnings[] = self::CONFLICTS_RECORDED;
        }
        $result->blockers = array_values(array_unique($result->blockers));
        return $result;
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
        if (self::source_userdata($fromuserid)) {
            $problems[] = self::SOURCE_USERDATA;
        }
        if (merge_evidence::for_pair($fromuserid, $touserid, true)) {
            $problems[] = self::CONFLICTS_RECORDED;
        }
        if (merge_evidence::pending_enrolments($fromuserid, $touserid)) {
            $problems[] = self::ENROLMENTS_PENDING;
        }
        if ($registry && !empty($registry->ssobinding)) {
            $approved = json_decode((string) $registry->ssobinding, true);
            if (is_array($approved) && !merge_policy::sso_binding_holds($approved, merge_policy::sso_binding($touserid)->binding)) {
                $problems[] = merge_policy::SSO_CHANGED;
            }
        }
        return $problems;
    }

    /**
     * Tables in which course work of the source is still present (not moved to the survivor).
     *
     * @param int $userid Source.
     * @return string[] Table names.
     */
    public static function source_userdata(int $userid): array {
        global $DB;
        $left = [];
        foreach (self::USERDATA_TABLES as $table) {
            if (self::table_exists($table) && $DB->record_exists($table, ['userid' => $userid])) {
                $left[] = $table;
            }
        }
        return $left;
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
