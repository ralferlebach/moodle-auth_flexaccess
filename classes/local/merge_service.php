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
 * Reconciles FlexAccess state after an external identity merge (LTI account linking, merge tools).
 *
 * Contract:
 *  - The source is the identity that is merged away (typically a temporary FlexAccess visitor), the
 *    target is the surviving, durable identity. The surviving account is always the target.
 *  - Every FlexAccess course enrolment of the source is mapped onto the target through
 *    enrol_flexaccess (owner of enrolments): new where the target had none, merged where it had one
 *    (active wins, the later end wins, "no end" wins). The target never drops to guest rights just
 *    because the source id is no longer used. Other (manual, LTI, ...) enrolments of the target are
 *    never touched.
 *  - Account permanence and course permanence stay separate: by default the course end time carries
 *    over unchanged. Only when the caller states that the merge establishes permanent course access
 *    are the FlexAccess end times of the transferred enrolments removed.
 *  - The target keeps no temporary FlexAccess state and no restriction role. It is unsuspended only
 *    when FlexAccess itself had suspended it; a suspension with another origin is left alone.
 *  - No FlexAccess metadata survives on the source (account row, tokens, queued mail, restriction) -
 *    but only once every postcondition of the merge holds (see {@see merge_guard::postconditions()}):
 *    no LTI binding or LTI membership still points to the source, the target holds no duplicate LTI
 *    membership and an administrative suspension of the target is still in place. Until then the pair
 *    is "review" in the {@see merge_registry} and the source metadata is kept for the administrator.
 *    The source Moodle user itself is not deleted here; that is the merging tool's decision.
 *  - A merge FlexAccess' policy does not allow (a durable FlexAccess identity merged into a non-FlexAccess
 *    target, or two durable identities without an administrator's approval) is refused: nothing changes.
 *  - Idempotent: repeating the reconciliation creates no second enrolment or role.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class merge_service {
    /**
     * Reconcile the FlexAccess state of a merged identity pair.
     *
     * @param int $sourceuserid Identity that is merged away.
     * @param int $targetuserid Surviving identity.
     * @param bool $permanentcourseaccess Whether the merge explicitly establishes permanent course access.
     * @param int|null $now Current time.
     * @param string $origin Who asks: hook (inside tool_mergeusers' transaction), event, admin, backfill.
     * @return \stdClass ->status (reconciled|nothingtodo|review|refused|invalid|locked), ->reasons (string[]),
     *     ->transferred, ->merged, ->restrictionlifted (bool), ->sourcecleaned (bool), ->targetlocked (bool: the
     *     surviving identity keeps a suspension FlexAccess did not set; it is left in place for an administrator).
     */
    public static function reconcile(
        int $sourceuserid,
        int $targetuserid,
        bool $permanentcourseaccess = false,
        ?int $now = null,
        string $origin = 'event'
    ): \stdClass {
        global $DB;
        $now = $now ?? time();
        $result = (object) [
            'status' => 'invalid',
            'reasons' => [],
            'transferred' => 0,
            'merged' => 0,
            'restrictionlifted' => false,
            'sourcecleaned' => false,
            'targetlocked' => false,
        ];
        if ($sourceuserid <= 0 || $targetuserid <= 0 || $sourceuserid === $targetuserid) {
            return $result;
        }
        if (!$DB->record_exists('user', ['id' => $targetuserid, 'deleted' => 0])) {
            return $result;
        }

        // Serialise per pair (ordered ids) so a merge event and the fallback reconciler cannot race.
        $ids = [$sourceuserid, $targetuserid];
        sort($ids);
        $lock = \core\lock\lock_config::get_lock_factory('auth_flexaccess_merge')->get_lock(implode('_', $ids), 10);
        if (!$lock) {
            $result->status = 'locked';
            return $result;
        }
        try {
            $transaction = $DB->start_delegated_transaction();
            try {
                $registry = merge_registry::record($sourceuserid, $targetuserid, $origin, null, $now);
                // 0. FlexAccess' policy for the pair. Inside tool_mergeusers' transaction (origin hook) a
                // violation refuses the merge. After the fact (event, backfill, re-check) the merge has
                // already happened: a violation of the direction rule is then a review case, and nothing
                // of the pair is changed, so that an administrator can still reverse it.
                $assessment = merge_guard::assess($sourceuserid, $targetuserid, $now);
                $blockers = $origin === 'hook'
                    ? $assessment->blockers
                    : array_values(array_diff($assessment->blockers, [merge_guard::NEEDS_APPROVAL]));
                if ($blockers) {
                    $status = $origin === 'hook' ? merge_registry::REFUSED : merge_registry::REVIEW;
                    $result->status = $status;
                    $result->reasons = $blockers;
                    merge_registry::finish($registry, $status, $result->reasons, [
                        'from' => self::facts($assessment->from),
                        'to' => self::facts($assessment->to),
                    ], $now);
                    $transaction->allow_commit();
                    return $result;
                }

                // 1. Course enrolments and FlexAccess course roles move to the surviving identity.
                if (method_exists('\enrol_flexaccess\api', 'transfer_user_enrolments')) {
                    $moved = \enrol_flexaccess\api::transfer_user_enrolments(
                        $sourceuserid,
                        $targetuserid,
                        $permanentcourseaccess,
                        $now
                    );
                    $result->transferred = (int) $moved->transferred;
                    $result->merged = (int) $moved->merged;
                }

                // 2. The surviving identity carries no temporary FlexAccess state. The lifecycle lifts
                // a suspension of the target only when FlexAccess itself set it; an administrative
                // suspension of the surviving identity stays and is reported, never lifted here.
                $target = $DB->get_record('auth_flexaccess_account', ['userid' => $targetuserid]);
                $result->targetlocked = !lifecycle::suspension_liftable($targetuserid);
                $targetmoved = true;
                if ($target && $target->accounttype === account_type::TEMPORARY_USER) {
                    // Refused for a suspension of foreign origin: the target keeps its state and
                    // restriction until an administrator decides (reported as targetlocked).
                    $targetmoved = lifecycle::transition_to_active_authenticated($targetuserid, $now);
                } else if ($target) {
                    lifecycle::normalise($targetuserid);
                }
                if ($targetmoved && self::holds_restriction($targetuserid)) {
                    self::unrestrict_completely($targetuserid);
                    $result->restrictionlifted = true;
                }

                // 3. Postconditions decide: only a complete merge loses the source's FlexAccess metadata.
                $result->reasons = merge_guard::postconditions($sourceuserid, $targetuserid, $registry);
                if (!$result->reasons) {
                    $result->sourcecleaned = self::clean_source($sourceuserid);
                }
                $changed = $result->transferred + $result->merged > 0 || $result->restrictionlifted || $result->sourcecleaned;
                if ($result->reasons) {
                    $result->status = merge_registry::REVIEW;
                } else {
                    $result->status = $changed ? 'reconciled' : 'nothingtodo';
                }
                merge_registry::finish(
                    $registry,
                    $result->reasons ? merge_registry::REVIEW : merge_registry::RECONCILED,
                    $result->reasons,
                    [
                        'transferred' => $result->transferred,
                        'merged' => $result->merged,
                        'restrictionlifted' => $result->restrictionlifted,
                        'sourcecleaned' => $result->sourcecleaned,
                        'targetlocked' => $result->targetlocked,
                        'from' => self::facts($assessment->from),
                        'to' => self::facts(merge_guard::identity($targetuserid)),
                    ],
                    $now
                );
                $transaction->allow_commit();
            } catch (\Throwable $e) {
                $transaction->rollback($e);
            }
        } finally {
            $lock->release();
        }
        return $result;
    }

    /**
     * Verify a merge after it was committed, and reconcile it if that has not happened yet.
     *
     * tool_mergeusers unsuspends the kept user in its own observer after the commit. When the surviving
     * identity was suspended by someone other than FlexAccess before the merge (recorded inside the
     * merge), that suspension is put back: a merge never lifts an administrative lock implicitly. The
     * pair then stays "review" until an administrator decides.
     *
     * @param int $sourceuserid Identity merged away.
     * @param int $targetuserid Surviving identity.
     * @param int|null $now Current time.
     * @return \stdClass ->status, ->reasons (string[]), ->suspensionrestored (bool).
     */
    public static function verify(int $sourceuserid, int $targetuserid, ?int $now = null): \stdClass {
        global $DB;
        $now = $now ?? time();
        $row = merge_registry::get($sourceuserid, $targetuserid) ?? merge_registry::record($sourceuserid, $targetuserid, 'event');
        if ((int) $row->timeconfirmed === 0 && merge_registry::confirm($sourceuserid, $targetuserid, null, 'verify', $now)) {
            $row = merge_registry::get($sourceuserid, $targetuserid);
        }
        $restored = false;
        if (
            (int) $row->targetsuspended === 1
                && $row->targetlockedby !== lifecycle::LOCKED_BY_FLEXACCESS
                && (int) $DB->get_field('user', 'suspended', ['id' => $targetuserid]) === 0
        ) {
            $DB->set_field('user', 'suspended', 1, ['id' => $targetuserid]);
            if ($DB->record_exists('auth_flexaccess_account', ['userid' => $targetuserid])) {
                $DB->set_field('auth_flexaccess_account', 'lockedby', $row->targetlockedby, ['userid' => $targetuserid]);
            }
            \core\session\manager::destroy_user_sessions($targetuserid);
            \core\event\user_updated::create_from_userid($targetuserid)->trigger();
            $restored = true;
        }
        if ($row->status !== merge_registry::RECONCILED || $restored) {
            $result = self::reconcile($sourceuserid, $targetuserid, false, $now, 'verify');
        } else {
            $reasons = merge_guard::postconditions($sourceuserid, $targetuserid, $row);
            if ($reasons) {
                $details = (array) json_decode((string) $row->details, true);
                merge_registry::finish($row, merge_registry::REVIEW, $reasons, $details, $now);
            }
            $result = (object) ['status' => $reasons ? merge_registry::REVIEW : 'reconciled', 'reasons' => $reasons];
        }
        $result->suspensionrestored = $restored;
        return $result;
    }

    /**
     * The facts of an identity as recorded in the registry (no contact data).
     *
     * @param \stdClass $identity Facts from {@see merge_guard::identity()}.
     * @return array
     */
    private static function facts(\stdClass $identity): array {
        return array_diff_key((array) $identity, ['email' => true]);
    }

    /**
     * Remove the FlexAccess metadata, tokens, queued mail and restriction of the source identity.
     *
     * @param int $userid Source user id.
     * @return bool Whether anything was removed.
     */
    private static function clean_source(int $userid): bool {
        global $DB;
        $changed = false;
        if (self::holds_restriction($userid)) {
            self::unrestrict_completely($userid);
            $changed = true;
        }
        foreach (['auth_flexaccess_account', 'auth_flexaccess_token'] as $table) {
            if ($DB->record_exists($table, ['userid' => $userid])) {
                $DB->delete_records($table, ['userid' => $userid]);
                $changed = true;
            }
        }
        if ($DB->record_exists('auth_flexaccess_mailqueue', ['userid' => $userid, 'status' => 'queued'])) {
            $DB->delete_records('auth_flexaccess_mailqueue', ['userid' => $userid, 'status' => 'queued']);
            $changed = true;
        }
        foreach (['auth_flexaccess_pendingemail', 'auth_flexaccess_followupsent', 'auth_flexaccess_pendingcredential'] as $pref) {
            unset_user_preference($pref, $userid);
        }
        return $changed;
    }

    /**
     * Remove every assignment of the restriction role, including historical ones without component.
     *
     * @param int $userid User id.
     * @return void
     */
    private static function unrestrict_completely(int $userid): void {
        if (method_exists('\enrol_flexaccess\local\participant_role', 'unrestrict_all')) {
            \enrol_flexaccess\local\participant_role::unrestrict_all($userid);
        } else {
            \enrol_flexaccess\local\participant_role::unrestrict($userid);
        }
    }

    /**
     * Whether the user holds the site-wide FlexAccess restriction role.
     *
     * @param int $userid User id.
     * @return bool
     */
    private static function holds_restriction(int $userid): bool {
        if (!class_exists('\enrol_flexaccess\local\participant_role')) {
            return false;
        }
        $roleid = \enrol_flexaccess\local\participant_role::get_restriction_id();
        return $roleid > 0 && user_has_role_assignment($userid, $roleid, \context_system::instance()->id);
    }
}
