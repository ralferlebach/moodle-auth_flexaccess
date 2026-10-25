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
 * Origin-type matrix, survivor policy and consolidation plan for merges involving FlexAccess (auth #13).
 *
 * Every identity is classified by how it logs in (its origin): a temporary FlexAccess visitor, a
 * FlexAccess account still waiting for its password, a durable FlexAccess account with its own password,
 * a local password account (manual, email), an auth_lti account, an institutionally managed account
 * (LDAP, OAuth 2, SAML, Shibboleth, CAS, ... - every other auth plugin) or an account without login.
 *
 * The matrix decides for a pair (source merged away -> survivor) whether FlexAccess lets the merge go
 * ahead. The guiding rules:
 *  - The survivor keeps its auth method and its password; a generic merge moves neither. The source's own
 *    login route is therefore lost (shown in the preview) - which is only acceptable when the survivor
 *    has a usable interactive login of its own.
 *  - A managed (institutional) identity is never merged away into a non-managed one: its SSO identity
 *    would be lost. Merge the other way round; two managed identities of different auth plugins have no
 *    safe direction and go to review.
 *  - Temporary and provisional identities are never promoted by a merge (no higher expiry, no ACTIVE
 *    state, no rights) - they may only be absorbed into a durable survivor.
 *  - Two durable identities (or any merge with conflicting course work) need an administrator's
 *    approval with an ownership proof first ({@see merge_registry::approve()}).
 *  - Pairs without any FlexAccess state are none of FlexAccess' business (the site's general merge tool
 *    decides alone).
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class merge_policy {
    /** Origin: temporary FlexAccess visitor (time-limited, restricted). */
    public const EPHEMERAL = 'ephemeral';
    /** Origin: FlexAccess account that has not set its password yet. */
    public const PENDING = 'pendingcredential';
    /** Origin: durable FlexAccess account with its own password. */
    public const FLEXACCESS = 'flexaccess';
    /** Origin: local password account (manual, email-based self-registration). */
    public const LOCAL = 'localpassword';
    /** Origin: auth_lti account (logs in only through LTI launches). */
    public const LTI = 'lti';
    /** Origin: institutionally managed account (every other auth plugin: LDAP, OAuth 2, SAML, ...). */
    public const INSTITUTIONAL = 'institutional';
    /** Origin: account without an interactive login (nologin, webservice, local account without password). */
    public const NOLOGIN = 'nologin';
    /** Origin: no such (undeleted) user. */
    public const MISSING = 'missing';

    /** Auth plugins whose password Moodle itself holds. */
    private const LOCAL_AUTHS = ['manual', 'email'];
    /** Auth plugins without interactive login. */
    private const NOLOGIN_AUTHS = ['nologin', 'webservice'];

    /** Origins that are durable identities of a person (their merge needs an approval). */
    public const DURABLE = [self::PENDING, self::FLEXACCESS, self::LOCAL, self::LTI, self::INSTITUTIONAL];

    /** Survivor rank: managed credentials first, then own passwords, LTI, provisional and temporary ones. */
    private const RANK = [
        self::INSTITUTIONAL => 6,
        self::FLEXACCESS => 5,
        self::LOCAL => 5,
        self::LTI => 3,
        self::PENDING => 2,
        self::EPHEMERAL => 1,
        self::NOLOGIN => 0,
        self::MISSING => -1,
    ];

    /** Blocker: a managed (institutional) identity would be merged away. */
    public const SOURCE_MANAGED = 'sourcemanaged';
    /** Blocker: two managed identities of different auth plugins - no safe direction. */
    public const NO_SAFE_DIRECTION = 'nosafedirection';
    /** Blocker: a durable identity would be merged into a temporary or provisional one. */
    public const WRONG_DIRECTION = 'wrongdirection';
    /** Blocker: the survivor has no interactive login at all. */
    public const TARGET_NO_LOGIN = 'targetnologin';
    /** Blocker: the survivor itself was merged away into another user. */
    public const TARGET_MERGED_AWAY = 'targetmergedaway';
    /** Blocker: the source was already merged into another survivor. */
    public const SOURCE_ALREADY_MERGED = 'sourcealreadymerged';

    /** Blocker: tool_mergeusers uses a table merger FlexAccess cannot wrap (collisions would not be archived). */
    public const MERGER_COVERAGE = 'mergercoverage';
    /** Blocker: the survivor's institutional login is of a kind FlexAccess cannot verify after a merge. */
    public const SSO_UNSUPPORTED = 'ssounsupported';
    /** Blocker: the survivor's OAuth 2 login has no linked login with an enabled issuer, so it cannot be verified. */
    public const SSO_UNVERIFIED = 'ssounverified';
    /** Blocker/postcondition: the survivor's institutional binding changed since the approval. */
    public const SSO_CHANGED = 'ssochanged';

    /** Auth plugins that bind the Moodle account by its username (and host), not by a table of external ids. */
    public const SSO_USERNAME_AUTHS = ['ldap', 'cas', 'shibboleth', 'db', 'saml2', 'mnet'];

    /**
     * Origin of an identity.
     *
     * @param \stdClass $identity Facts from {@see merge_guard::identity()}.
     * @return string One of the origin constants.
     */
    public static function origin(\stdClass $identity): string {
        if (!$identity->exists) {
            return self::MISSING;
        }
        if ($identity->auth === 'flexaccess') {
            if ($identity->accounttype === account_type::TEMPORARY_USER) {
                return self::EPHEMERAL;
            }
            return $identity->haspassword ? self::FLEXACCESS : self::PENDING;
        }
        if (in_array($identity->auth, self::LOCAL_AUTHS, true)) {
            return $identity->haspassword ? self::LOCAL : self::NOLOGIN;
        }
        if ($identity->auth === 'lti') {
            return self::LTI;
        }
        if (in_array($identity->auth, self::NOLOGIN_AUTHS, true)) {
            return self::NOLOGIN;
        }
        return self::INSTITUTIONAL;
    }

    /**
     * The login route an identity of this origin uses (shown in the preview: kept or lost).
     *
     * @param string $origin Origin.
     * @return string Route code: password, sso, ltilaunch, setpassword, temporary, none.
     */
    public static function login_route(string $origin): string {
        return [
            self::FLEXACCESS => 'password',
            self::LOCAL => 'password',
            self::INSTITUTIONAL => 'sso',
            self::LTI => 'ltilaunch',
            self::PENDING => 'setpassword',
            self::EPHEMERAL => 'temporary',
        ][$origin] ?? 'none';
    }

    /**
     * Whether FlexAccess has a stake in a pair (an account row or a FlexAccess enrolment on either side).
     *
     * @param int $fromuserid Source.
     * @param int $touserid Survivor.
     * @return bool
     */
    public static function involved(int $fromuserid, int $touserid): bool {
        global $DB;
        [$insql, $params] = $DB->get_in_or_equal([$fromuserid, $touserid], SQL_PARAMS_NAMED);
        if ($DB->record_exists_select('auth_flexaccess_account', "userid $insql", $params)) {
            return true;
        }
        return $DB->record_exists_sql(
            "SELECT 1
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid AND e.enrol = 'flexaccess'
              WHERE ue.userid $insql",
            $params
        );
    }

    /**
     * Matrix verdict for a pair, independent of approvals.
     *
     * @param \stdClass $from Source facts.
     * @param \stdClass $to Survivor facts.
     * @param string|null $sourceorigin The source's origin as recorded before the merge (its FlexAccess row may
     *     have been removed since), or null to classify it now.
     * @return string[] Blockers (empty when the direction is allowed).
     */
    public static function direction_blockers(\stdClass $from, \stdClass $to, ?string $sourceorigin = null): array {
        $source = $sourceorigin ?? self::origin($from);
        $target = self::origin($to);
        if ($target === self::MISSING) {
            return [merge_guard::TARGET_MISSING];
        }
        if ($target === self::NOLOGIN && in_array($source, self::DURABLE, true)) {
            return [self::TARGET_NO_LOGIN];
        }
        if ($source === self::INSTITUTIONAL) {
            if ($target !== self::INSTITUTIONAL) {
                return [self::SOURCE_MANAGED];
            }
            return $from->auth === $to->auth ? [] : [self::NO_SAFE_DIRECTION];
        }
        if (in_array($source, [self::FLEXACCESS, self::LOCAL], true)) {
            // The own password is lost: only a survivor with its own interactive login may absorb it.
            return in_array($target, [self::FLEXACCESS, self::LOCAL, self::INSTITUTIONAL], true)
                ? [] : [merge_guard::SOURCE_DURABLE];
        }
        if (in_array($source, [self::LTI, self::PENDING], true) && $target === self::EPHEMERAL) {
            return [self::WRONG_DIRECTION];
        }
        return [];
    }

    /**
     * Blockers from earlier merges: no chains (a survivor merged away) and no second survivor for one source.
     *
     * @param int $fromuserid Source.
     * @param int $touserid Survivor.
     * @return string[]
     */
    public static function chain_blockers(int $fromuserid, int $touserid): array {
        global $DB;
        $blockers = [];
        $merged = 'timeconfirmed > 0';
        if ($DB->record_exists_select(merge_registry::TABLE, "fromuserid = :u AND $merged", ['u' => $touserid])) {
            $blockers[] = self::TARGET_MERGED_AWAY;
        }
        if (
            $DB->record_exists_select(
                merge_registry::TABLE,
                "fromuserid = :u AND touserid <> :t AND $merged",
                ['u' => $fromuserid, 't' => $touserid]
            )
        ) {
            $blockers[] = self::SOURCE_ALREADY_MERGED;
        }
        return $blockers;
    }

    /**
     * Collisions that need a review: rows of both users with the same collision key in a table whose
     * resolution can lose work ({@see merge_coverage}). Read before the merge; inside and after it the
     * archive ({@see merge_evidence}) takes its place.
     *
     * @param int $fromuserid Source.
     * @param int $touserid Survivor.
     * @return array<string, string[]> table => colliding key values ("value/value").
     */
    public static function conflicts(int $fromuserid, int $touserid): array {
        $out = [];
        foreach (merge_coverage::keys() as $table => [$userfields, $keyfields]) {
            foreach ($userfields as $userfield) {
                foreach (merge_evidence::collisions($table, $userfield, $keyfields, $fromuserid, $touserid, true) as $collision) {
                    $kind = merge_coverage::collision_kind($table, $collision['source'], $collision['target']);
                    if ($kind === merge_coverage::KIND_REVIEW) {
                        $out[$table][] = implode('/', array_map('strval', $collision['values']));
                    }
                }
            }
        }
        ksort($out);
        return $out;
    }

    /**
     * How the survivor's institutional login is bound, and a fingerprint of that binding.
     *
     * OAuth 2 logins are bound by auth_oauth2_linked_login (issuer + external username) - tool_mergeusers moves
     * the source's rows and leaves the survivor's; the username-based plugins ({@see self::SSO_USERNAME_AUTHS})
     * find the account by its username (and host), which a merge does not change. For any other auth plugin
     * FlexAccess cannot tell how the login is found, so the merge is refused.
     *
     * @param int $userid Survivor.
     * @return \stdClass ->kind (oauth2|username|unsupported), ->supported (bool), ->verified (bool: a working
     *     binding exists), ->binding (array: identity fields and, for OAuth 2, the linked logins).
     */
    public static function sso_binding(int $userid): \stdClass {
        global $DB;
        $user = $DB->get_record('user', ['id' => $userid], 'id, auth, username, idnumber, email, mnethostid');
        $binding = $user ? [
            'auth' => (string) $user->auth,
            'username' => (string) $user->username,
            'idnumber' => (string) $user->idnumber,
            'email' => (string) $user->email,
            'mnethostid' => (int) $user->mnethostid,
        ] : [];
        $result = (object) ['kind' => 'unsupported', 'supported' => false, 'verified' => false, 'binding' => $binding];
        if (!$user) {
            return $result;
        }
        if ($user->auth === 'oauth2') {
            $links = $DB->get_records_sql(
                "SELECT l.id, l.issuerid, l.username, i.enabled
                   FROM {auth_oauth2_linked_login} l
                   JOIN {oauth2_issuer} i ON i.id = l.issuerid
                  WHERE l.userid = :userid AND (l.confirmtoken IS NULL OR l.confirmtoken = '')
               ORDER BY l.issuerid, l.username",
                ['userid' => $userid]
            );
            $result->kind = 'oauth2';
            $result->supported = true;
            // A link counts only if auth_oauth2's own lookup resolves it to this user (enabled issuer, confirmed,
            // and - from Moodle 5.x - a link token that has not expired).
            $resolves = static function ($l) use ($userid): bool {
                if ((int) $l->enabled !== 1) {
                    return false;
                }
                $match = \auth_oauth2\api::match_username_to_user($l->username, new \core\oauth2\issuer((int) $l->issuerid));
                return $match && (int) $match->get('userid') === $userid;
            };
            $result->binding['links'] = array_values(array_map(
                static fn($l): string => $l->issuerid . ':' . $l->username,
                array_filter($links, $resolves)
            ));
            $result->verified = $result->binding['links'] !== [];
        } else if (in_array($user->auth, self::SSO_USERNAME_AUTHS, true)) {
            $result->kind = 'username';
            $result->supported = true;
            $result->verified = $user->username !== '';
        }
        return $result;
    }

    /**
     * Whether the survivor's institutional binding still holds what was approved: same identity fields and at
     * least the approved linked logins (the merge may add the source's).
     *
     * @param array $approved Binding at approval time.
     * @param array $current Binding now.
     * @return bool
     */
    public static function sso_binding_holds(array $approved, array $current): bool {
        $fields = ['auth', 'username', 'idnumber', 'email', 'mnethostid'];
        foreach ($fields as $field) {
            if (($approved[$field] ?? null) !== ($current[$field] ?? null)) {
                return false;
            }
        }
        return !array_diff($approved['links'] ?? [], $current['links'] ?? []);
    }

    /**
     * Survivor rank of an identity (higher survives).
     *
     * @param \stdClass $identity Facts.
     * @return array Comparable tuple: rank, activity, -userid.
     */
    private static function rank(\stdClass $identity): array {
        return [self::RANK[self::origin($identity)], (int) $identity->grades + (int) $identity->enrolments, -$identity->userid];
    }

    /**
     * A deterministic consolidation plan for two or more identities of one person.
     *
     * The survivor is chosen by origin (managed > own password > LTI > provisional > temporary), then by
     * course activity, then the older account. Every other identity becomes one step "source -> survivor"
     * (never a chain), assessed like a single merge. The plan is the same whatever order the ids come in.
     *
     * @param int[] $userids Identities.
     * @param int|null $now Current time.
     * @return \stdClass ->survivor (int|null), ->steps (\stdClass[] from, to, blockers, warnings, needsapproval,
     *     approved, lostroutes), ->review (bool: no safe survivor or a step is blocked), ->reasons (string[]).
     */
    public static function plan(array $userids, ?int $now = null): \stdClass {
        $userids = array_values(array_unique(array_map('intval', $userids)));
        sort($userids);
        $plan = (object) ['survivor' => null, 'steps' => [], 'review' => false, 'reasons' => []];
        if (count($userids) < 2) {
            $plan->review = true;
            $plan->reasons[] = 'notenoughidentities';
            return $plan;
        }
        $identities = [];
        foreach ($userids as $userid) {
            $identities[$userid] = merge_guard::identity($userid);
        }
        $managed = array_filter($identities, static fn($i): bool => self::origin($i) === self::INSTITUTIONAL);
        if (count(array_unique(array_map(static fn($i): string => $i->auth, $managed))) > 1) {
            $plan->review = true;
            $plan->reasons[] = self::NO_SAFE_DIRECTION;
        }
        $ranked = $identities;
        uasort($ranked, static fn($a, $b): int => self::rank($b) <=> self::rank($a));
        $plan->survivor = (int) array_key_first($ranked);
        foreach ($userids as $userid) {
            if ($userid === $plan->survivor) {
                continue;
            }
            $assessment = merge_guard::assess($userid, $plan->survivor, $now);
            $step = (object) [
                'from' => $userid,
                'to' => $plan->survivor,
                'blockers' => $assessment->blockers,
                'warnings' => $assessment->warnings,
                'needsapproval' => $assessment->needsapproval,
                'approved' => $assessment->approved,
                'lostroutes' => $assessment->lostroutes,
            ];
            if (array_diff($step->blockers, [merge_guard::NEEDS_APPROVAL])) {
                $plan->review = true;
                $plan->reasons = array_merge($plan->reasons, array_diff($step->blockers, [merge_guard::NEEDS_APPROVAL]));
            }
            $plan->steps[] = $step;
        }
        $plan->reasons = array_values(array_unique($plan->reasons));
        return $plan;
    }
}
