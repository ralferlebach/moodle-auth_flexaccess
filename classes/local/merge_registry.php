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
 * Registry of identity merges: one row per merged pair, with review state and per-record outcome.
 *
 * The registry is what makes a merge accountable after the fact: an administrator's approval of a merge
 * of two durable identities, the surviving identity's suspension before the merge, the reconciliation
 * outcome, and every postcondition that is not met. A pair stays "review" until a re-check passes; the
 * merged-away identity's FlexAccess metadata is kept until then.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class merge_registry {
    /** Table name. */
    public const TABLE = 'auth_flexaccess_merge';

    /** Status: recorded, reconciliation not done yet. */
    public const PENDING = 'pending';
    /** Status: an administrator approved merging this pair of durable identities. */
    public const APPROVED = 'approved';
    /** Status: reconciled, every postcondition met. */
    public const RECONCILED = 'reconciled';
    /** Status: merged, but at least one postcondition is not met; needs an administrator. */
    public const REVIEW = 'review';
    /** Status: FlexAccess refused the merge (fail closed); nothing of the pair was changed by FlexAccess. */
    public const REFUSED = 'refused';

    /** How long an approval stays valid, in seconds. */
    public const APPROVAL_LIFETIME = DAYSECS;

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
     * Record a pair (idempotent); an existing row keeps its status unless one is given.
     *
     * @param int $fromuserid Identity merged away.
     * @param int $touserid Surviving identity.
     * @param string $origin hook, event, admin or backfill.
     * @param string|null $status Status to set, or null to keep/default.
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
        $now = $now ?? time();
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
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $row->id = (int) $DB->insert_record(self::TABLE, $row);
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
     * @param string $status New status.
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
        $row->timemodified = $now ?? time();
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
        return $row && $row->status === self::APPROVED
            && (int) $row->timemodified > ($now ?? time()) - self::APPROVAL_LIFETIME;
    }

    /**
     * Rows that need an administrator (review, refused, or still pending), newest first.
     *
     * @param int $limit Maximum rows; 0 = all.
     * @return \stdClass[]
     */
    public static function open(int $limit = 200): array {
        global $DB;
        return array_values($DB->get_records_select(
            self::TABLE,
            'status IN (:review, :refused, :pending, :approved)',
            ['review' => self::REVIEW, 'refused' => self::REFUSED, 'pending' => self::PENDING, 'approved' => self::APPROVED],
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
            'fromuserid = :from AND status IN (:pending, :review)',
            ['from' => $fromuserid, 'pending' => self::PENDING, 'review' => self::REVIEW]
        );
    }
}
