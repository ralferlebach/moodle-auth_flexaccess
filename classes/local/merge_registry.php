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
 * Registry of identity merges: one row per pair, with its stage, the evidence of the merge and the outcome.
 *
 * A registry row is NOT proof that two users were merged (auth_flexaccess #14). The stage (status) says
 * where FlexAccess is with a pair; whether the Moodle users really were merged is recorded separately as
 * evidence: the id of the successful tool_mergeusers log record for exactly this pair. Only a confirmed
 * merge - evidence present and still verifiable - unlocks actions that move data of the merged-away
 * identity (re-pointing LTI bindings and memberships).
 *
 * Stages:
 *  - preapproved: an administrator confirmed that two durable identities belong to one person; nothing
 *    is merged yet (the approval is valid for {@see self::APPROVAL_LIFETIME}).
 *  - pending: recorded without evidence (a direct reconciliation, or an earlier merge from the backfill).
 *  - mergeinprogress: tool_mergeusers is merging the pair, inside its transaction.
 *  - mergeconfirmed: tool_mergeusers reported the merge as successful; reconciliation still to run.
 *  - reconciled: confirmed and every postcondition met.
 *  - review: at least one postcondition is not met (or no evidence exists); needs an administrator.
 *  - refused: FlexAccess refused the merge (fail closed).
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class merge_registry {
    /** Table name. */
    public const TABLE = 'auth_flexaccess_merge';

    /** Stage: recorded without evidence of a merge. */
    public const PENDING = 'pending';
    /** Stage: an administrator approved merging this pair of durable identities; no merge yet. */
    public const PREAPPROVED = 'preapproved';
    /** Stage: tool_mergeusers is merging the pair (inside its transaction). */
    public const MERGE_IN_PROGRESS = 'mergeinprogress';
    /** Stage: tool_mergeusers confirmed the merge; FlexAccess' reconciliation is still to run. */
    public const MERGE_CONFIRMED = 'mergeconfirmed';
    /** Stage: reconciled, every postcondition met. */
    public const RECONCILED = 'reconciled';
    /** Stage: at least one postcondition is not met; needs an administrator. */
    public const REVIEW = 'review';
    /** Stage: FlexAccess refused the merge (fail closed); nothing of the pair was changed by FlexAccess. */
    public const REFUSED = 'refused';

    /** How long an approval stays valid, in seconds. */
    public const APPROVAL_LIFETIME = DAYSECS;

    /** Ownership proof: the person re-authenticated with both accounts in front of the administrator. */
    public const PROOF_REAUTHENTICATION = 'reauthentication';
    /** Ownership proof: the identity provider confirmed that both identities belong to the person. */
    public const PROOF_IDP = 'idp';
    /** Ownership proof: independent documents or records (ID check, student record, signed request). */
    public const PROOF_DOCUMENTS = 'documents';
    /** Accepted ownership proofs. A matching e-mail address or name is never one of them. */
    public const PROOFS = [self::PROOF_REAUTHENTICATION, self::PROOF_IDP, self::PROOF_DOCUMENTS];
    /** Minimum length of the evidence description, without e-mail addresses. */
    public const EVIDENCE_MIN_LENGTH = 20;

    /**
     * The registry row of a pair, if any.
     *
     * @param int $fromuserid Identity merged away.
     * @param int $touserid Surviving identity.
     * @return \stdClass|null
     */
    public static function get(int $fromuserid, int $touserid): ?\stdClass {
        global $DB;
        return $DB->get_record(self::TABLE, ['fromuserid' => $fromuserid, 'touserid' => $touserid]) ?: null;
    }

    /**
     * Record a pair (idempotent); an existing row keeps its stage unless one is given.
     *
     * @param int $fromuserid Identity merged away.
     * @param int $touserid Surviving identity.
     * @param string $origin hook, event, admin, verify or backfill.
     * @param string|null $status Stage to set, or null to keep/default.
     * @param int|null $now Current time.
     * @return \stdClass The row.
     */
    public static function record(
        int $fromuserid,
        int $touserid,
        string $origin,
        ?string $status = null,
        ?int $now = null
    ): \stdClass {
        global $DB;
        $now = $now ?? \core\di::get(\core\clock::class)->time();
        $row = self::get($fromuserid, $touserid);
        if ($row) {
            if ($status !== null) {
                $row->status = $status;
            }
            $row->timemodified = $now;
            $DB->update_record(self::TABLE, $row);
            return $row;
        }
        $row = (object) [
            'fromuserid' => $fromuserid,
            'touserid' => $touserid,
            'origin' => $origin,
            'status' => $status ?? self::PENDING,
            'reasons' => null,
            'details' => null,
            'targetsuspended' => 0,
            'targetlockedby' => null,
            'approvedby' => null,
            'timeapproved' => 0,
            'prooftype' => null,
            'proofevidence' => null,
            'ssobinding' => null,
            'mergelogid' => null,
            'timeconfirmed' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $row->id = (int) $DB->insert_record(self::TABLE, $row);
        return $row;
    }

    /**
     * Why an ownership proof is not acceptable, or null when it is.
     *
     * The evidence must describe an independent check; e-mail addresses are removed before its length is
     * judged, so "same address" or a pasted address is never accepted as proof.
     *
     * @param string $proof Proof kind (one of {@see self::PROOFS}).
     * @param string $evidence The administrator's description of the evidence.
     * @return string|null 'prooftype' or 'proofevidence', or null.
     */
    public static function proof_problem(string $proof, string $evidence): ?string {
        if (!in_array($proof, self::PROOFS, true)) {
            return 'prooftype';
        }
        $withoutaddresses = preg_replace('/[^\s@]+@[^\s@]+/u', '', $evidence);
        $text = trim(preg_replace('/\s+/u', ' ', (string) $withoutaddresses));
        return \core_text::strlen($text) < self::EVIDENCE_MIN_LENGTH ? 'proofevidence' : null;
    }

    /**
     * Record an administrator's approval (consent) for merging a pair of durable identities.
     *
     * @param int $fromuserid Identity to be merged away.
     * @param int $touserid Identity to keep.
     * @param int $approverid The administrator who confirmed the ownership of both accounts.
     * @param string $proof Kind of ownership proof (one of {@see self::PROOFS}).
     * @param string $evidence Description of the independent evidence.
     * @param int|null $now Current time.
     * @return \stdClass The row.
     * @throws \invalid_parameter_exception Without an accepted proof (nothing is recorded).
     */
    public static function approve(
        int $fromuserid,
        int $touserid,
        int $approverid,
        string $proof,
        string $evidence,
        ?int $now = null
    ): \stdClass {
        global $DB;
        $now = $now ?? \core\di::get(\core\clock::class)->time();
        if (self::proof_problem($proof, $evidence) !== null) {
            throw new \invalid_parameter_exception('An approval needs an accepted ownership proof with evidence.');
        }
        $row = self::get($fromuserid, $touserid);
        $merged = $row && (int) $row->timeconfirmed > 0;
        $row = self::record($fromuserid, $touserid, 'admin', $merged ? null : self::PREAPPROVED, $now);
        $row->approvedby = $approverid;
        $row->timeapproved = $now;
        $row->prooftype = $proof;
        $row->proofevidence = trim($evidence);
        // The survivor's institutional login as approved; the merge must leave it intact (auth_flexaccess #13).
        $to = merge_guard::identity($touserid);
        $row->ssobinding = merge_policy::origin($to) === merge_policy::INSTITUTIONAL
            ? json_encode(merge_policy::sso_binding($touserid)->binding) : null;
        $DB->update_record(self::TABLE, $row);
        return $row;
    }

    /**
     * Remember the surviving identity's suspension as it was before the merge (taken inside the merge).
     *
     * @param \stdClass $row Registry row.
     * @param int $touserid Surviving identity.
     * @return void
     */
    public static function snapshot_target(\stdClass $row, int $touserid): void {
        global $DB;
        $row->targetsuspended = (int) $DB->get_field('user', 'suspended', ['id' => $touserid]);
        $row->targetlockedby = $DB->get_field('auth_flexaccess_account', 'lockedby', ['userid' => $touserid]) ?: null;
        $DB->update_record(self::TABLE, $row);
    }

    /**
     * Store the outcome of a reconciliation or re-check.
     *
     * @param \stdClass $row Registry row.
     * @param string $status New stage.
     * @param string[] $reasons Unmet postconditions or refusal reasons.
     * @param array $details Per-record outcome (JSON-encoded).
     * @param int|null $now Current time.
     * @return void
     */
    public static function finish(\stdClass $row, string $status, array $reasons, array $details, ?int $now = null): void {
        global $DB;
        $row->status = $status;
        $row->reasons = $reasons ? implode(',', array_values(array_unique($reasons))) : null;
        $row->details = json_encode($details);
        $row->timemodified = $now ?? \core\di::get(\core\clock::class)->time();
        $DB->update_record(self::TABLE, $row);
    }

    /**
     * Whether a valid administrator approval exists for merging this pair.
     *
     * @param int $fromuserid Identity merged away.
     * @param int $touserid Surviving identity.
     * @param int|null $now Current time.
     * @return bool
     */
    public static function is_approved(int $fromuserid, int $touserid, ?int $now = null): bool {
        $row = self::get($fromuserid, $touserid);
        if (!$row || empty($row->approvedby) || !in_array((string) ($row->prooftype ?? ''), self::PROOFS, true)) {
            return false;
        }
        if ((int) $row->timeconfirmed > 0) {
            // A merge that happened: the approval must not have been older than its lifetime when the merge
            // happened (re-checks may come much later); an approval given afterwards - the review of a merge
            // done without FlexAccess' check - counts as well.
            return (int) $row->timeapproved > (int) $row->timeconfirmed - self::APPROVAL_LIFETIME;
        }
        return (int) $row->timeapproved > ($now ?? \core\di::get(\core\clock::class)->time()) - self::APPROVAL_LIFETIME;
    }

    /**
     * The successful tool_mergeusers log record of exactly this pair, if it exists.
     *
     * Supports both log schemas of tool_mergeusers (status 'success', or the older success flag).
     *
     * @param int $fromuserid Identity merged away.
     * @param int $touserid Surviving identity.
     * @param int|null $logid A specific log record to check (from the merge event), or null for the latest.
     * @return \stdClass|null id, timemodified.
     */
    public static function mergeusers_log(int $fromuserid, int $touserid, ?int $logid = null): ?\stdClass {
        global $DB;
        if (!$DB->get_manager()->table_exists('tool_mergeusers')) {
            return null;
        }
        $columns = $DB->get_columns('tool_mergeusers');
        if (isset($columns['status'])) {
            $success = "status = 'success'";
        } else if (isset($columns['success'])) {
            $success = 'success = 1';
        } else {
            return null;
        }
        $params = ['from' => $fromuserid, 'to' => $touserid];
        $where = "fromuserid = :from AND touserid = :to AND $success";
        if ($logid !== null) {
            $where .= ' AND id = :id';
            $params['id'] = $logid;
        }
        $rows = $DB->get_records_select('tool_mergeusers', $where, $params, 'id DESC', 'id, timemodified', 0, 1);
        return $rows ? reset($rows) : null;
    }

    /**
     * Record the evidence that tool_mergeusers merged the pair (idempotent).
     *
     * @param int $fromuserid Identity merged away.
     * @param int $touserid Surviving identity.
     * @param int|null $logid Log record id reported by the merge event, or null to look it up.
     * @param string $origin Who confirms: event, verify, backfill.
     * @param int|null $now Current time.
     * @return bool Whether the merge is confirmed now.
     */
    public static function confirm(int $fromuserid, int $touserid, ?int $logid, string $origin, ?int $now = null): bool {
        global $DB;
        $log = self::mergeusers_log($fromuserid, $touserid, $logid);
        if (!$log) {
            return false;
        }
        $now = $now ?? \core\di::get(\core\clock::class)->time();
        $row = self::get($fromuserid, $touserid) ?? self::record($fromuserid, $touserid, $origin, null, $now);
        if ((int) $row->mergelogid === (int) $log->id && (int) $row->timeconfirmed > 0) {
            return true;
        }
        $row->mergelogid = (int) $log->id;
        $row->timeconfirmed = $now;
        if (in_array($row->status, [self::PENDING, self::PREAPPROVED, self::MERGE_IN_PROGRESS], true)) {
            $row->status = self::MERGE_CONFIRMED;
        }
        $row->timemodified = $now;
        $DB->update_record(self::TABLE, $row);
        return true;
    }

    /**
     * Whether the pair was merged, by evidence that is still verifiable now.
     *
     * @param int $fromuserid Identity merged away.
     * @param int $touserid Surviving identity.
     * @return bool
     */
    public static function is_confirmed(int $fromuserid, int $touserid): bool {
        $row = self::get($fromuserid, $touserid);
        return $row && (int) $row->timeconfirmed > 0 && !empty($row->mergelogid)
            && self::mergeusers_log($fromuserid, $touserid, (int) $row->mergelogid) !== null;
    }

    /**
     * Rows that need an administrator (review, refused, unconfirmed, approved but not merged), newest first.
     *
     * @param int $limit Maximum rows; 0 = all.
     * @return \stdClass[]
     */
    public static function open(int $limit = 200): array {
        global $DB;
        return array_values($DB->get_records_select(
            self::TABLE,
            'status IN (:review, :refused, :pending, :approved, :inprogress, :confirmed)',
            [
                'review' => self::REVIEW,
                'refused' => self::REFUSED,
                'pending' => self::PENDING,
                'approved' => self::PREAPPROVED,
                'inprogress' => self::MERGE_IN_PROGRESS,
                'confirmed' => self::MERGE_CONFIRMED,
            ],
            'timemodified DESC, id DESC',
            '*',
            0,
            $limit
        ));
    }

    /**
     * Number of merges whose postconditions failed or that FlexAccess refused.
     *
     * @return int
     */
    public static function count_needing_review(): int {
        global $DB;
        return $DB->count_records_select(self::TABLE, 'status IN (:review, :refused)', [
            'review' => self::REVIEW,
            'refused' => self::REFUSED,
        ]);
    }

    /**
     * Whether a reconciliation of a pair with this merged-away identity is still outstanding.
     *
     * @param int $fromuserid Identity merged away.
     * @return bool
     */
    public static function has_outstanding_source(int $fromuserid): bool {
        global $DB;
        return $DB->record_exists_select(
            self::TABLE,
            'fromuserid = :from AND status IN (:pending, :review, :inprogress, :confirmed)',
            [
                'from' => $fromuserid,
                'pending' => self::PENDING,
                'review' => self::REVIEW,
                'inprogress' => self::MERGE_IN_PROGRESS,
                'confirmed' => self::MERGE_CONFIRMED,
            ]
        );
    }
}
