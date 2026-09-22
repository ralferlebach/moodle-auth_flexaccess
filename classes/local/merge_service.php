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
 *  - No FlexAccess metadata survives on the source (account row, tokens, queued mail, restriction).
 *    The source Moodle user itself is not deleted here; that is the merging tool's decision.
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
     * @return \stdClass ->status (reconciled|nothingtodo|invalid|locked), ->transferred, ->merged,
     *     ->restrictionlifted (bool), ->sourcecleaned (bool).
     */
    public static function reconcile(
        int $sourceuserid,
        int $targetuserid,
        bool $permanentcourseaccess = false,
        ?int $now = null
    ): \stdClass {
        global $DB;
        $now = $now ?? time();
        $result = (object) [
            'status' => 'invalid',
            'transferred' => 0,
            'merged' => 0,
            'restrictionlifted' => false,
            'sourcecleaned' => false,
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

                // 2. The surviving identity carries no temporary FlexAccess state.
                $target = $DB->get_record('auth_flexaccess_account', ['userid' => $targetuserid]);
                if ($target && $target->accounttype === account_type::TEMPORARY_USER) {
                    // FlexAccess had suspended it only if it was expired; the transition unsuspends.
                    lifecycle::transition_to_active_authenticated($targetuserid, $now);
                } else if ($target) {
                    lifecycle::normalise($targetuserid);
                }
                if (self::holds_restriction($targetuserid)) {
                    \enrol_flexaccess\local\participant_role::unrestrict($targetuserid);
                    $result->restrictionlifted = true;
                }

                // 3. Nothing FlexAccess-specific remains on the merged-away identity.
                $result->sourcecleaned = self::clean_source($sourceuserid);
                $transaction->allow_commit();
            } catch (\Throwable $e) {
                $transaction->rollback($e);
            }
        } finally {
            $lock->release();
        }
        $changed = $result->transferred + $result->merged > 0 || $result->restrictionlifted || $result->sourcecleaned;
        $result->status = $changed ? 'reconciled' : 'nothingtodo';
        return $result;
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
            \enrol_flexaccess\local\participant_role::unrestrict($userid);
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
