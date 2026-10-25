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
 * Read-only diagnosis of LTI 1.3 identities next to FlexAccess identities.
 *
 * Two facts cannot be prevented from FlexAccess without patching Core: a resource published with
 * learner provisioning "automatic only" (AUTO_ONLY) creates an auth_lti account on the first launch
 * (and NRPS member sync may do so in advance), and a person who also holds a FlexAccess account then
 * has two Moodle users. This class makes both visible: the published resources whose provisioning mode
 * leads there, and the pairs that look like such a duplicate. A shared e-mail address is only a hint
 * for a human review - it is never used to bind or merge anything.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class lti_identity {
    /** auth_lti provisioning mode: create and bind a new account automatically. */
    public const PROVISIONING_AUTO_ONLY = 1;

    /**
     * Published LTI 1.3 resources in FlexAccess courses whose learner provisioning creates new accounts.
     *
     * @param int $limit Maximum rows; 0 = all.
     * @return \stdClass[] toolid, courseid, provisioningmodelearner.
     */
    public static function auto_provisioning_resources(int $limit = 200): array {
        global $DB;
        $dbman = $DB->get_manager();
        if (!$dbman->table_exists('enrol_lti_tools')) {
            return [];
        }
        return array_values($DB->get_records_sql(
            "SELECT t.id AS toolid, e.courseid, t.provisioningmodelearner
               FROM {enrol_lti_tools} t
               JOIN {enrol} e ON e.id = t.enrolid AND e.enrol = 'lti'
              WHERE t.ltiversion = :version
                AND t.provisioningmodelearner = :auto
                AND EXISTS (SELECT 1 FROM {enrol} fe WHERE fe.courseid = e.courseid AND fe.enrol = 'flexaccess')
           ORDER BY e.courseid, t.id",
            ['version' => 'LTI-1p3', 'auto' => self::PROVISIONING_AUTO_ONLY],
            0,
            $limit
        ));
    }

    /**
     * auth_lti users bound to a platform identity that share the e-mail address of a durable FlexAccess
     * account - candidates for the explicit, reviewed merge workflow (never merged automatically).
     *
     * @param int $limit Maximum rows; 0 = all.
     * @return \stdClass[] pairkey, bindingid, ltiuserid, flexaccessuserid, issuer.
     */
    public static function duplicate_candidates(int $limit = 200): array {
        global $DB;
        // The first column must be unique: one binding can match several FlexAccess accounts.
        $key = $DB->sql_concat('l.id', "'-'", 'f.id');
        $sql = "SELECT $key AS pairkey, l.id AS bindingid, l.userid AS ltiuserid, f.id AS flexaccessuserid, l.issuer
                  FROM {auth_lti_linked_login} l
                  JOIN {user} lu ON lu.id = l.userid AND lu.auth = 'lti' AND lu.deleted = 0
                  JOIN {user} f ON f.auth = 'flexaccess' AND f.deleted = 0 AND f.id <> lu.id
                       AND " . $DB->sql_equal('f.email', 'lu.email', false) . "
                  JOIN {auth_flexaccess_account} a ON a.userid = f.id AND a.accounttype = :type
              ORDER BY l.userid, f.id";
        return array_values($DB->get_records_sql($sql, ['type' => account_type::AUTHENTICATED_USER], 0, $limit));
    }

    /**
     * LTI bindings that point to FlexAccess identities that are not durable (bindings from before the
     * binding policy, or created by other means). Launches through them are refused at login.
     *
     * @param int $limit Maximum rows; 0 = all.
     * @return \stdClass[] bindingid, userid, issuer, reason.
     */
    public static function non_durable_bindings(int $limit = 200): array {
        global $DB;
        $rows = $DB->get_records_sql(
            "SELECT l.id AS bindingid, l.userid, l.issuer
               FROM {auth_lti_linked_login} l
               JOIN {auth_flexaccess_account} a ON a.userid = l.userid
           ORDER BY l.id",
            []
        );
        $out = [];
        foreach ($rows as $row) {
            $reason = lti_guard::durable_identity_problem((int) $row->userid);
            if ($reason !== '') {
                $row->reason = $reason;
                $out[] = $row;
                if ($limit > 0 && count($out) >= $limit) {
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * LTI bindings whose Moodle user was deleted or no longer exists (e.g. merged away and removed).
     *
     * @param int $limit Maximum rows; 0 = all.
     * @return \stdClass[] bindingid, userid, issuer.
     */
    public static function stale_bindings(int $limit = 200): array {
        global $DB;
        return array_values($DB->get_records_sql(
            "SELECT l.id AS bindingid, l.userid, l.issuer
               FROM {auth_lti_linked_login} l
          LEFT JOIN {user} u ON u.id = l.userid
              WHERE u.id IS NULL OR u.deleted = 1
           ORDER BY l.id",
            [],
            0,
            $limit
        ));
    }

    /** Repoint refusal: no verifiable tool_mergeusers evidence that the pair was merged. */
    public const REPOINT_NOT_CONFIRMED = 'mergenotconfirmed';
    /** Repoint refusal: the merged-away user still exists as an active, independent account. */
    public const REPOINT_SOURCE_ACTIVE = 'sourceactive';
    /** Repoint refusal: the survivor is not a canonical identity (missing, deleted or merged away itself). */
    public const REPOINT_TARGET_NOT_CANONICAL = 'targetnotcanonical';
    /** Repoint refusal: the administrator did not confirm that both accounts belong to the same person. */
    public const REPOINT_OWNERSHIP = 'ownershipnotverified';
    /** Repoint refusal: the survivor is already a member of a tool the source belongs to. */
    public const REPOINT_CONFLICT = 'membershipconflict';

    /**
     * Why the LTI bindings and memberships of a pair must not be re-pointed now (read-only).
     *
     * Re-pointing moves data of the merged-away identity, so it needs a merge that really happened: the
     * successful tool_mergeusers log record of exactly this pair (a registry row alone is no proof), a
     * merged-away account that is no longer an active, independent user (suspended or deleted, as
     * tool_mergeusers leaves it) and a survivor that is a canonical identity.
     *
     * @param int $fromuserid Merged-away user.
     * @param int $touserid Surviving user.
     * @return string[] Refusal codes (REPOINT_*); empty when re-pointing is allowed.
     */
    public static function repoint_blockers(int $fromuserid, int $touserid): array {
        global $DB;
        $blockers = [];
        if (
            $fromuserid <= 0 || $touserid <= 0 || $fromuserid === $touserid
                || !merge_registry::is_confirmed($fromuserid, $touserid)
        ) {
            $blockers[] = self::REPOINT_NOT_CONFIRMED;
        }
        $source = $DB->get_record('user', ['id' => $fromuserid], 'id, deleted, suspended');
        if ($source && (int) $source->deleted === 0 && (int) $source->suspended === 0) {
            $blockers[] = self::REPOINT_SOURCE_ACTIVE;
        }
        $target = $DB->get_record('user', ['id' => $touserid], 'id, deleted');
        $mergedaway = $DB->record_exists_select(
            merge_registry::TABLE,
            'fromuserid = :to AND timeconfirmed > 0',
            ['to' => $touserid]
        );
        if (!$target || (int) $target->deleted === 1 || $mergedaway) {
            $blockers[] = self::REPOINT_TARGET_NOT_CANONICAL;
        }
        return $blockers;
    }

    /**
     * Point the LTI bindings and memberships of a merged-away user to the surviving user.
     *
     * The authorised adapter for the two Core tables Moodle offers no API for: auth_lti's
     * create_user_binding() never rebinds an existing (iss, sub), and enrol_lti has no merge API. It runs
     * in two phases. Validation (no write): the blockers of {@see self::repoint_blockers()}, the
     * administrator's ownership confirmation, and the memberships - a tool both users belong to would give
     * the survivor two grade-delivery targets, so the whole repoint is refused. Mutation: in one
     * transaction, followed by a postcondition check that rolls everything back if anything still points
     * to the source. A repeated request finds nothing to move.
     *
     * @param int $fromuserid Merged-away user.
     * @param int $touserid Surviving user.
     * @param bool $ownershipverified The administrator confirmed that both accounts belong to one person.
     * @return \stdClass ->status (repointed|nothingtodo|refused|conflict), ->reasons (string[]),
     *     ->bindings, ->ltiusers (moved), ->conflicts (toolids), ->mergelogid.
     */
    public static function repoint(int $fromuserid, int $touserid, bool $ownershipverified): \stdClass {
        global $DB;
        $result = (object) [
            'status' => 'refused',
            'reasons' => self::repoint_blockers($fromuserid, $touserid),
            'bindings' => 0,
            'ltiusers' => 0,
            'conflicts' => [],
            'mergelogid' => (int) (merge_registry::get($fromuserid, $touserid)->mergelogid ?? 0),
        ];
        if (!$ownershipverified) {
            $result->reasons[] = self::REPOINT_OWNERSHIP;
        }
        if ($result->reasons) {
            return $result;
        }
        $haslti = $DB->get_manager()->table_exists('enrol_lti_users');
        $bindings = $DB->get_records('auth_lti_linked_login', ['userid' => $fromuserid], 'id', 'id');
        $members = $haslti ? $DB->get_records('enrol_lti_users', ['userid' => $fromuserid], 'id', 'id, toolid') : [];
        foreach ($members as $member) {
            if ($DB->record_exists('enrol_lti_users', ['userid' => $touserid, 'toolid' => $member->toolid])) {
                $result->conflicts[] = (int) $member->toolid;
            }
        }
        if ($result->conflicts) {
            $result->status = 'conflict';
            $result->reasons = [self::REPOINT_CONFLICT];
            return $result;
        }
        if (!$bindings && !$members) {
            $result->status = 'nothingtodo';
            return $result;
        }
        $transaction = $DB->start_delegated_transaction();
        $now = \core\di::get(\core\clock::class)->time();
        foreach ($bindings as $binding) {
            $DB->update_record('auth_lti_linked_login', (object) [
                'id' => $binding->id,
                'userid' => $touserid,
                'timemodified' => $now,
            ]);
            $result->bindings++;
        }
        foreach ($members as $member) {
            $DB->set_field('enrol_lti_users', 'userid', $touserid, ['id' => $member->id]);
            $result->ltiusers++;
        }
        $left = $DB->record_exists('auth_lti_linked_login', ['userid' => $fromuserid])
            || ($haslti && $DB->record_exists('enrol_lti_users', ['userid' => $fromuserid]));
        if ($left) {
            // A concurrent write re-created a pointer to the source: nothing of this repoint is kept.
            $transaction->rollback(new \moodle_exception('repointincomplete', 'auth_flexaccess'));
        }
        $transaction->allow_commit();
        $result->status = 'repointed';
        return $result;
    }

    /**
     * Remove an LTI binding that points to a non-durable FlexAccess identity or a deleted user.
     *
     * The platform user is then asked again on the next launch and can link a durable account.
     *
     * @param int $bindingid auth_lti_linked_login id.
     * @return bool Whether the binding was removed.
     */
    public static function remove_binding(int $bindingid): bool {
        global $DB;
        $binding = $DB->get_record('auth_lti_linked_login', ['id' => $bindingid]);
        if (!$binding) {
            return false;
        }
        $user = $DB->get_record('user', ['id' => $binding->userid], 'id, deleted');
        $removable = !$user || (int) $user->deleted === 1
            || ($DB->record_exists('auth_flexaccess_account', ['userid' => $binding->userid])
                && lti_guard::durable_identity_problem((int) $binding->userid) !== '');
        if (!$removable) {
            return false;
        }
        $DB->delete_records('auth_lti_linked_login', ['id' => $bindingid]);
        return true;
    }
}
