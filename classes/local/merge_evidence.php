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
 * Archive of colliding rows, captured inside a merge before tool_mergeusers resolves them (auth #13).
 *
 * When both users of a merge have a row with the same collision key ({@see merge_coverage::keys()}) -
 * a grade for the same grade item, the same quiz attempt number, a submission to the same assignment,
 * completion of the same activity, but also the same group or role - tool_mergeusers keeps one row and
 * removes, renumbers or merges the other. The table mergers in {@see \auth_flexaccess\local\mergeusers}
 * call {@see self::capture_table()} right before tool_mergeusers processes a table (inside its transaction),
 * so the rows of both users are kept here. A rolled-back merge rolls its archive back with it.
 *
 * Kinds: "review" rows keep a merge under review until an administrator acknowledges them; "benign" rows
 * are archived as acknowledged; "enrolment" rows (FlexAccess enrolments) are merged with FlexAccess' rule
 * and only count as done when that merge succeeded, or when an administrator explicitly discarded one of
 * them with a reason ({@see self::decide_enrolment()}).
 *
 * Data minimisation: only the columns needed to understand a collision are archived ({@see self::columns()}):
 * the row id, the user and key columns, numeric columns (grades, states, times, ids) and the text columns of
 * an explicit per-table list (grade feedback, a submission status). Text columns of unknown tables are never
 * copied, and no column whose name marks a credential (token, secret, password, hash, push id, ...) is ever
 * copied; a key column of that kind is kept as a non-reversible fingerprint only.
 *
 * Bounds: one merge archives at most {@see self::max_rows()} rows and {@see self::max_bytes()} bytes; beyond
 * that the table merger throws, tool_mergeusers rolls the whole merge back and FlexAccess records the refusal
 * ({@see self::REFUSED_LIMIT}) - a merge never runs with evidence left out.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class merge_evidence {
    /** Table name. */
    public const TABLE = 'auth_flexaccess_mergeevid';

    /** Table of the FlexAccess enrolments whose source values are recorded. */
    public const ENROLMENTS = 'user_enrolments';

    /** Refusal code: the collisions of the merge exceed the archive bounds. */
    public const REFUSED_LIMIT = 'evidencelimit';

    /** Decision: the recorded FlexAccess enrolment was merged into the survivor's enrolment. */
    public const DECISION_MERGED = 'merged';
    /** Decision: an administrator discarded a recorded enrolment that could not be merged (with a reason). */
    public const DECISION_DISCARDED = 'discarded';
    /** Decision: an administrator reviewed an archived collision. */
    public const DECISION_REVIEWED = 'reviewed';

    /** Default bound of archived rows per merge (both users, all tables). */
    public const DEFAULT_MAX_ROWS = 5000;
    /** Default bound of archived JSON per merge, in bytes. */
    public const DEFAULT_MAX_BYTES = 8388608;

    /** Column names that mark a credential or a device secret: never copied (substring match). */
    private const SECRET_PARTS = [
        'token', 'secret', 'passw', 'hash', 'salt', 'apikey', 'privatekey', 'pushid', 'session', 'cookie',
        'credential', 'uuid', 'signature', 'nonce', 'cipher',
    ];
    /** Column names that mark a credential or a device secret: never copied (exact match). */
    private const SECRET_NAMES = ['key', 'sid', 'pin', 'otp', 'password', 'secret', 'token'];

    /** Text columns archived in addition to ids, user/key columns and numeric columns, per table. */
    private const TEXT_COLUMNS = [
        'grade_grades' => ['feedback', 'information'],
        'assign_submission' => ['status'],
        'quiz_attempts' => ['state'],
        'lesson_attempts' => ['useranswer'],
        'survey_answers' => ['answer1', 'answer2'],
        'tool_policy_acceptances' => ['lang'],
        'user_devices' => ['appid', 'platform'],
    ];

    /** @var array<string, bool> Involvement per pair, for the duration of one request (one merge). */
    private static array $involved = [];

    /** @var array<string, array{rows: int, bytes: int}> Archived volume per pair in this request. */
    private static array $usage = [];

    /** @var array<string, string> Refusal per pair raised in this request (survives the rollback). */
    private static array $refused = [];

    /** @var array<string, array{0: string[], 1: string[]}> Archived and omitted columns per table and keys. */
    private static array $columns = [];

    /**
     * Whether FlexAccess has a stake in the pair (cached: called once per table of a merge).
     *
     * @param int $fromuserid Source.
     * @param int $touserid Survivor.
     * @return bool
     */
    private static function involved(int $fromuserid, int $touserid): bool {
        $key = $fromuserid . '_' . $touserid;
        return self::$involved[$key] ??= merge_policy::involved($fromuserid, $touserid);
    }

    /**
     * Whether a column name marks a credential or secret.
     *
     * @param string $name Column name.
     * @return bool
     */
    public static function is_secret(string $name): bool {
        $name = strtolower($name);
        if (in_array($name, self::SECRET_NAMES, true)) {
            return true;
        }
        foreach (self::SECRET_PARTS as $part) {
            if (strpos($name, $part) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Non-reversible stand-in for a secret value (so equal values still compare equal).
     *
     * @param mixed $value Value.
     * @return string|null
     */
    public static function fingerprint($value): ?string {
        return $value === null ? null : 'sha256:' . substr(hash('sha256', (string) $value), 0, 16);
    }

    /**
     * The columns of a table that are archived, and the names of those that are not.
     *
     * Positive list: id, the user and key columns, numeric columns, and the text columns listed for the table
     * in {@see self::TEXT_COLUMNS}; never a column whose name marks a secret (a secret key column is selected to
     * find the collision but archived as a fingerprint only).
     *
     * @param string $table Table name.
     * @param string[] $userfields User columns.
     * @param string[] $keyfields Key columns.
     * @return array{0: string[], 1: string[]} [archived columns, omitted columns]
     */
    public static function columns(string $table, array $userfields = [], array $keyfields = []): array {
        global $DB;
        $cachekey = $table . '|' . implode(',', $userfields) . '|' . implode(',', $keyfields);
        if (isset(self::$columns[$cachekey])) {
            return self::$columns[$cachekey];
        }
        $keep = [];
        $omit = [];
        $text = self::TEXT_COLUMNS[$table] ?? [];
        foreach ($DB->get_columns($table) as $name => $column) {
            $structural = $name === 'id' || in_array($name, $userfields, true) || in_array($name, $keyfields, true);
            $numeric = in_array($column->meta_type, ['I', 'N', 'F', 'R'], true);
            if ($structural || (!self::is_secret($name) && ($numeric || in_array($name, $text, true)))) {
                $keep[] = $name;
            } else {
                $omit[] = $name;
            }
        }
        return self::$columns[$cachekey] = [$keep, $omit];
    }

    /**
     * A row reduced to what may be archived (also for rows archived by earlier versions).
     *
     * With the table installed the schema decides ({@see self::columns()}); otherwise only numeric values of
     * columns that do not mark a secret are kept. Secret key columns become fingerprints. The names (never the
     * values) of dropped columns are listed in "_omitted".
     *
     * @param string $table Table name.
     * @param object|array|null $row Row.
     * @param string[] $keyfields Key columns.
     * @return \stdClass|null
     */
    public static function sanitise_row(string $table, $row, array $keyfields = []): ?\stdClass {
        global $DB;
        if ($row === null) {
            return null;
        }
        $row = (array) $row;
        $known = $DB->get_manager()->table_exists($table) ? self::columns($table, [], $keyfields)[0] : null;
        $out = [];
        $omitted = (array) ($row['_omitted'] ?? []);
        unset($row['_omitted']);
        foreach ($row as $name => $value) {
            $name = (string) $name;
            if (in_array($name, $keyfields, true) && self::is_secret($name)) {
                $out[$name] = self::is_fingerprint($value) ? $value : self::fingerprint($value);
                continue;
            }
            $allowed = $known !== null
                ? in_array($name, $known, true) && !self::is_secret($name)
                : !self::is_secret($name) && ($value === null || is_int($value) || is_float($value) || is_numeric($value));
            if ($allowed) {
                $out[$name] = $value;
            } else {
                $omitted[] = $name;
            }
        }
        if ($omitted) {
            $out['_omitted'] = array_values(array_unique($omitted));
        }
        return (object) $out;
    }

    /**
     * Whether a value is a fingerprint made by {@see self::fingerprint()}.
     *
     * @param mixed $value Value.
     * @return bool
     */
    private static function is_fingerprint($value): bool {
        return is_string($value) && preg_match('/^sha256:[0-9a-f]{16}$/', $value) === 1;
    }

    /**
     * Archived rows of a record, decoded and reduced again (defence in depth for display and export).
     *
     * @param \stdClass $record Evidence record.
     * @param string $side "sourcerows" or "targetrows".
     * @return \stdClass[]
     */
    public static function rows(\stdClass $record, string $side): array {
        $keyfields = array_filter(explode(',', (string) ($record->keyfields ?? '')));
        $rows = json_decode((string) ($record->$side ?? ''));
        return array_values(array_filter(array_map(
            static fn($row) => self::sanitise_row((string) $record->tablename, $row, $keyfields),
            is_array($rows) ? $rows : []
        )));
    }

    /**
     * Key values of a record with secret key columns as fingerprints.
     *
     * @param string[] $keyfields Key columns.
     * @param array $values Values in key order.
     * @return array
     */
    private static function safe_keyvalues(array $keyfields, array $values): array {
        $out = [];
        foreach (array_values($values) as $i => $value) {
            $field = $keyfields[$i] ?? '';
            $out[] = self::is_secret($field) && !self::is_fingerprint($value) ? self::fingerprint($value) : $value;
        }
        return $out;
    }

    /**
     * Bound of archived rows per merge (site setting, default {@see self::DEFAULT_MAX_ROWS}).
     *
     * @return int
     */
    public static function max_rows(): int {
        $value = (int) get_config('auth_flexaccess', 'mergeevidencemaxrows');
        return $value > 0 ? $value : self::DEFAULT_MAX_ROWS;
    }

    /**
     * Bound of archived bytes per merge (site setting, default {@see self::DEFAULT_MAX_BYTES}).
     *
     * @return int
     */
    public static function max_bytes(): int {
        $value = (int) get_config('auth_flexaccess', 'mergeevidencemaxbytes');
        return $value > 0 ? $value : self::DEFAULT_MAX_BYTES;
    }

    /**
     * Count rows and bytes against the bounds of the merge; throw (the merge rolls back) when they are exceeded.
     *
     * @param int $fromuserid Source.
     * @param int $touserid Survivor.
     * @param int $rows Additional rows.
     * @param int $bytes Additional bytes.
     * @param string $table Table (for the message).
     * @return void
     * @throws \moodle_exception When a bound is exceeded.
     */
    private static function account(int $fromuserid, int $touserid, int $rows, int $bytes, string $table): void {
        $key = $fromuserid . '_' . $touserid;
        $usage = self::$usage[$key] ?? ['rows' => 0, 'bytes' => 0];
        $usage['rows'] += $rows;
        $usage['bytes'] += $bytes;
        self::$usage[$key] = $usage;
        if ($usage['rows'] > self::max_rows() || $usage['bytes'] > self::max_bytes()) {
            self::$refused[$key] = self::REFUSED_LIMIT;
            throw new \moodle_exception('mergerefused_evidencelimit', 'auth_flexaccess', '', (object) [
                'table' => $table,
                'rows' => $usage['rows'],
                'bytes' => $usage['bytes'],
                'maxrows' => self::max_rows(),
                'maxbytes' => self::max_bytes(),
            ]);
        }
    }

    /**
     * Refusal raised while archiving the collisions of a pair in this request (read after the rollback).
     *
     * @param int $fromuserid Source.
     * @param int $touserid Survivor.
     * @return string|null
     */
    public static function refusal(int $fromuserid, int $touserid): ?string {
        return self::$refused[$fromuserid . '_' . $touserid] ?? null;
    }

    /**
     * Rows of both users that collide on a key, grouped by key value.
     *
     * Two queries per table and user column - the source's colliding rows and the survivor's colliding rows -
     * read as record sets and counted against the bounds while they are read (no query per key value).
     *
     * @param string $table Table name.
     * @param string $userfield User column.
     * @param string[] $keyfields Collision key columns.
     * @param int $fromuserid Source.
     * @param int $touserid Survivor.
     * @param bool $preview A preview before the merge: rows are not counted against the bounds; reading stops at
     *     the row bound (the preview shows the first collisions only).
     * @return array<string, array{values: array, source: \stdClass[], target: \stdClass[]}> keyed by JSON of the
     *     (archived, secret-free) key values.
     */
    public static function collisions(
        string $table,
        string $userfield,
        array $keyfields,
        int $fromuserid,
        int $touserid,
        bool $preview = false
    ): array {
        global $DB;
        [$columns, $omitted] = self::columns($table, [$userfield], $keyfields);
        $join = implode(' AND ', array_map(static fn(string $f): string => "b.$f = a.$f", $keyfields));
        $select = implode(', ', array_map(static fn(string $c): string => "a.$c", $columns));
        $sql = "SELECT $select
                  FROM {" . $table . "} a
                 WHERE a.$userfield = :self
                   AND EXISTS (SELECT 1 FROM {" . $table . "} b WHERE b.$userfield = :other AND $join)
              ORDER BY a.id";
        $out = [];
        foreach (['source' => [$fromuserid, $touserid], 'target' => [$touserid, $fromuserid]] as $side => [$self, $other]) {
            $rs = $DB->get_recordset_sql($sql, ['self' => $self, 'other' => $other], 0, $preview ? self::max_rows() : 0);
            foreach ($rs as $row) {
                $raw = [];
                foreach ($keyfields as $field) {
                    $raw[] = $row->$field;
                }
                $values = array_combine($keyfields, self::safe_keyvalues($keyfields, $raw));
                $json = json_encode(array_values($values));
                $clean = self::sanitise_row($table, $row, $keyfields);
                if ($omitted) {
                    // Names only: what exists but was deliberately not archived.
                    $clean->_omitted = $omitted;
                }
                if (!$preview) {
                    try {
                        self::account($fromuserid, $touserid, 1, strlen((string) json_encode($clean)), $table);
                    } catch (\moodle_exception $e) {
                        $rs->close();
                        throw $e;
                    }
                }
                $out[$json] = $out[$json] ?? ['values' => $values, 'source' => [], 'target' => []];
                $out[$json][$side][] = $clean;
            }
            $rs->close();
        }
        return array_filter($out, static fn(array $c): bool => $c['source'] && $c['target']);
    }

    /**
     * Archive the collisions of one table (idempotent per pair, table and key value).
     *
     * @param string $table Table name.
     * @param string[] $userfields User columns that form the collision key with the key fields.
     * @param string[] $keyfields Collision key columns.
     * @param int $fromuserid Source.
     * @param int $touserid Survivor.
     * @param int|null $now Current time.
     * @return int Number of archived collisions that need a review.
     */
    public static function capture_table(
        string $table,
        array $userfields,
        array $keyfields,
        int $fromuserid,
        int $touserid,
        ?int $now = null
    ): int {
        global $DB;
        if (!$keyfields || $fromuserid <= 0 || $touserid <= 0 || !self::involved($fromuserid, $touserid)) {
            return 0;
        }
        $now = $now ?? \core\di::get(\core\clock::class)->time();
        $review = 0;
        foreach ($userfields as $userfield) {
            $collisions = self::collisions($table, $userfield, $keyfields, $fromuserid, $touserid);
            if (!$collisions) {
                continue;
            }
            $known = self::archived_keys($fromuserid, $touserid, $table);
            $records = [];
            foreach ($collisions as $json => $collision) {
                if (isset($known[$json])) {
                    continue;
                }
                $kind = merge_coverage::collision_kind($table, $collision['source'], $collision['target']);
                $first = reset($collision['values']);
                $records[] = (object) [
                    'fromuserid' => $fromuserid,
                    'touserid' => $touserid,
                    'tablename' => $table,
                    'kind' => $kind,
                    'keyfields' => implode(',', $keyfields),
                    'keyvalues' => $json,
                    'conflictkey' => is_numeric($first) ? (int) $first : 0,
                    'sourcerows' => json_encode($collision['source']),
                    'targetrows' => json_encode($collision['target']),
                    'acknowledgedby' => null,
                    // Benign collisions are archived as settled; reviews wait for an administrator.
                    'timeacknowledged' => $kind === merge_coverage::KIND_BENIGN ? $now : 0,
                    'timecreated' => $now,
                ];
                if ($kind === merge_coverage::KIND_REVIEW) {
                    $review++;
                }
            }
            // One bulk insert per table and user column instead of one statement per collision.
            if ($records) {
                $DB->insert_records(self::TABLE, $records);
            }
        }
        return $review;
    }

    /**
     * Key values already archived for a pair and table (one query per table).
     *
     * @param int $fromuserid Source.
     * @param int $touserid Survivor.
     * @param string $table Table.
     * @return array<string, true>
     */
    private static function archived_keys(int $fromuserid, int $touserid, string $table): array {
        global $DB;
        $known = [];
        $rs = $DB->get_recordset(
            self::TABLE,
            ['fromuserid' => $fromuserid, 'touserid' => $touserid, 'tablename' => $table],
            '',
            'id, keyvalues'
        );
        foreach ($rs as $row) {
            $known[(string) $row->keyvalues] = true;
        }
        $rs->close();
        return $known;
    }

    /**
     * Record the source's FlexAccess enrolments in instances where the survivor is enrolled too.
     *
     * A generic merge keeps the survivor's enrolment and deletes the source's one; the person's entitlement
     * from the source (a later end, an active status) would be lost. The recorded values are merged into the
     * survivor's enrolment afterwards by {@see self::apply_enrolments()} with FlexAccess' rule.
     *
     * @param int $fromuserid Source.
     * @param int $touserid Survivor.
     * @param int|null $now Current time.
     * @return int Number of recorded enrolments.
     */
    public static function capture_enrolments(int $fromuserid, int $touserid, ?int $now = null): int {
        global $DB;
        if ($fromuserid <= 0 || $touserid <= 0) {
            return 0;
        }
        $now = $now ?? \core\di::get(\core\clock::class)->time();
        [$columns] = self::columns(self::ENROLMENTS, ['userid'], ['enrolid']);
        $select = static fn(string $alias): string => implode(', ', array_map(
            static fn(string $c): string => "$alias.$c AS {$alias}_$c",
            $columns
        ));
        $rows = $DB->get_records_sql(
            "SELECT a.id, " . $select('a') . ", " . $select('b') . "
               FROM {user_enrolments} a
               JOIN {enrol} e ON e.id = a.enrolid AND e.enrol = 'flexaccess'
               JOIN {user_enrolments} b ON b.enrolid = a.enrolid AND b.userid = :to
              WHERE a.userid = :from",
            ['from' => $fromuserid, 'to' => $touserid]
        );
        $split = static function (\stdClass $row, string $alias) use ($columns): \stdClass {
            $out = [];
            foreach ($columns as $c) {
                $out[$c] = $row->{$alias . '_' . $c};
            }
            return (object) $out;
        };
        $count = 0;
        foreach ($rows as $row) {
            $source = $split($row, 'a');
            $target = $split($row, 'b');
            $known = [
                'fromuserid' => $fromuserid,
                'touserid' => $touserid,
                'kind' => merge_coverage::KIND_ENROLMENT,
                'conflictkey' => (int) $source->enrolid,
            ];
            if ($DB->record_exists(self::TABLE, $known)) {
                continue;
            }
            $sourcejson = json_encode([$source]);
            $targetjson = json_encode([$target]);
            self::account($fromuserid, $touserid, 2, strlen($sourcejson) + strlen($targetjson), self::ENROLMENTS);
            $DB->insert_record(self::TABLE, (object) [
                'fromuserid' => $fromuserid,
                'touserid' => $touserid,
                'tablename' => self::ENROLMENTS,
                'kind' => merge_coverage::KIND_ENROLMENT,
                'keyfields' => 'enrolid',
                'keyvalues' => json_encode([(int) $source->enrolid]),
                'conflictkey' => (int) $source->enrolid,
                'sourcerows' => $sourcejson,
                'targetrows' => $targetjson,
                'acknowledgedby' => null,
                'timeacknowledged' => 0,
                'timecreated' => $now,
            ]);
            $count++;
        }
        return $count;
    }

    /**
     * Merge the recorded source enrolments into the survivor's enrolments.
     *
     * A record counts as done only when its values were merged into the survivor's enrolment (decision
     * "merged"). Without a FlexAccess instance or a survivor enrolment it stays open - the merge is then under
     * review ({@see merge_guard::ENROLMENTS_PENDING}) and a later re-check merges it once the state is
     * corrected, or an administrator discards it with a reason ({@see self::decide_enrolment()}).
     *
     * @param int $fromuserid Source.
     * @param int $touserid Survivor.
     * @param int|null $now Current time.
     * @return \stdClass ->merged (int), ->pending (int[] enrol ids), ->results (array per enrol id: before, after).
     */
    public static function apply_enrolments(int $fromuserid, int $touserid, ?int $now = null): \stdClass {
        global $DB;
        $result = (object) ['merged' => 0, 'pending' => [], 'results' => []];
        if (!method_exists('\\enrol_flexaccess\\api', 'merge_recorded_enrolment')) {
            return $result;
        }
        $now = $now ?? \core\di::get(\core\clock::class)->time();
        foreach (self::open_enrolments($fromuserid, $touserid) as $record) {
            $source = self::rows($record, 'sourcerows')[0] ?? null;
            $enrolid = (int) $record->conflictkey;
            if ($source && $DB->record_exists('user_enrolments', ['id' => $source->id, 'userid' => $fromuserid])) {
                // Still on the source: the regular transfer merges it.
                continue;
            }
            if ($source && \enrol_flexaccess\api::merge_recorded_enrolment($touserid, $enrolid, $source)) {
                $after = $DB->get_record('user_enrolments', ['enrolid' => $enrolid, 'userid' => $touserid]);
                $result->results[$enrolid] = [
                    'source' => self::enrolment_state($source),
                    'target' => self::enrolment_state(self::rows($record, 'targetrows')[0] ?? null),
                    'after' => self::enrolment_state($after ?: null),
                ];
                $DB->update_record(self::TABLE, (object) [
                    'id' => $record->id,
                    'timeacknowledged' => $now,
                    'decision' => self::DECISION_MERGED,
                ]);
                $result->merged++;
            } else {
                $result->pending[] = $enrolid;
            }
        }
        return $result;
    }

    /**
     * Status, start and end of an enrolment row.
     *
     * @param \stdClass|null $row Row.
     * @return array|null
     */
    public static function enrolment_state(?\stdClass $row): ?array {
        return $row ? [
            'status' => (int) $row->status,
            'timestart' => (int) $row->timestart,
            'timeend' => (int) $row->timeend,
        ] : null;
    }

    /**
     * Recorded FlexAccess enrolments of a pair without a decision.
     *
     * @param int $fromuserid Source.
     * @param int $touserid Survivor.
     * @return \stdClass[]
     */
    public static function open_enrolments(int $fromuserid, int $touserid): array {
        global $DB;
        return array_values($DB->get_records(self::TABLE, [
            'fromuserid' => $fromuserid,
            'touserid' => $touserid,
            'kind' => merge_coverage::KIND_ENROLMENT,
            'timeacknowledged' => 0,
        ], 'id ASC'));
    }

    /**
     * Recorded enrolments of a pair whose values could not be merged yet (the source row is gone).
     *
     * @param int $fromuserid Source.
     * @param int $touserid Survivor.
     * @return int[] Enrol ids.
     */
    public static function pending_enrolments(int $fromuserid, int $touserid): array {
        global $DB;
        $pending = [];
        foreach (self::open_enrolments($fromuserid, $touserid) as $record) {
            $source = self::rows($record, 'sourcerows')[0] ?? null;
            if (!$source || !$DB->record_exists('user_enrolments', ['id' => $source->id, 'userid' => $fromuserid])) {
                $pending[] = (int) $record->conflictkey;
            }
        }
        return $pending;
    }

    /**
     * Archived collisions of a pair.
     *
     * @param int $fromuserid Source.
     * @param int $touserid Survivor.
     * @param bool $openonly Only records without a decision.
     * @param string[] $kinds Kinds to include (default: those that need a review).
     * @return \stdClass[]
     */
    public static function for_pair(
        int $fromuserid,
        int $touserid,
        bool $openonly = false,
        array $kinds = [merge_coverage::KIND_REVIEW]
    ): array {
        global $DB;
        [$insql, $params] = $DB->get_in_or_equal($kinds, SQL_PARAMS_NAMED);
        $where = "fromuserid = :from AND touserid = :to AND kind $insql" . ($openonly ? ' AND timeacknowledged = 0' : '');
        return array_values($DB->get_records_select(
            self::TABLE,
            $where,
            ['from' => $fromuserid, 'to' => $touserid] + $params,
            'tablename ASC, id ASC'
        ));
    }

    /**
     * An administrator confirms having reviewed the archived collisions of a pair (the kept records are correct).
     *
     * Only collisions of kind "review": a recorded FlexAccess enrolment that could not be merged is never closed
     * this way; it needs its own decision ({@see self::decide_enrolment()}).
     *
     * @param int $fromuserid Source.
     * @param int $touserid Survivor.
     * @param int $adminid Acknowledging administrator.
     * @param int|null $now Current time.
     * @return int Number of acknowledged records.
     */
    public static function acknowledge(int $fromuserid, int $touserid, int $adminid, ?int $now = null): int {
        global $DB;
        $now = $now ?? \core\di::get(\core\clock::class)->time();
        $open = self::for_pair($fromuserid, $touserid, true, [merge_coverage::KIND_REVIEW]);
        foreach ($open as $row) {
            $DB->update_record(self::TABLE, (object) [
                'id' => $row->id,
                'acknowledgedby' => $adminid,
                'timeacknowledged' => $now,
                'decision' => self::DECISION_REVIEWED,
            ]);
        }
        return count($open);
    }

    /**
     * An administrator discards one recorded FlexAccess enrolment that could not be merged, with a reason.
     *
     * Only an open enrolment record whose merge is pending (the source's row is gone) can be discarded; the
     * archived values stay. The decision, the administrator, the time and the reason are recorded on the
     * evidence row; nothing else of the pair changes.
     *
     * @param int $evidenceid Evidence record.
     * @param int $fromuserid Source (must match the record).
     * @param int $touserid Survivor (must match the record).
     * @param string $reason Reason (at least {@see merge_registry::EVIDENCE_MIN_LENGTH} characters without e-mail addresses).
     * @param int $adminid Deciding administrator.
     * @param int|null $now Current time.
     * @return string|null Null on success, otherwise a problem code (notfound, notpending, reason).
     */
    public static function decide_enrolment(
        int $evidenceid,
        int $fromuserid,
        int $touserid,
        string $reason,
        int $adminid,
        ?int $now = null
    ): ?string {
        global $DB;
        $record = $DB->get_record(self::TABLE, [
            'id' => $evidenceid,
            'fromuserid' => $fromuserid,
            'touserid' => $touserid,
            'kind' => merge_coverage::KIND_ENROLMENT,
        ]);
        if (!$record) {
            return 'notfound';
        }
        $pending = self::pending_enrolments($fromuserid, $touserid);
        if ((int) $record->timeacknowledged > 0 || !in_array((int) $record->conflictkey, $pending, true)) {
            return 'notpending';
        }
        if (merge_registry::proof_problem(merge_registry::PROOFS[0], $reason) !== null) {
            return 'reason';
        }
        $DB->update_record(self::TABLE, (object) [
            'id' => $record->id,
            'acknowledgedby' => $adminid,
            'timeacknowledged' => $now ?? \core\di::get(\core\clock::class)->time(),
            'decision' => self::DECISION_DISCARDED,
            'decisionreason' => trim($reason),
        ]);
        return null;
    }

    /**
     * Delete settled evidence older than the retention period (scheduled task).
     *
     * Only records with a decision (or benign records) of pairs whose merge is reconciled; open records and
     * records of pairs under review are kept regardless of age.
     *
     * @param int $days Retention in days (0 keeps everything).
     * @param int|null $now Current time.
     * @return int Number of deleted records.
     */
    public static function purge_settled(int $days, ?int $now = null): int {
        global $DB;
        if ($days <= 0) {
            return 0;
        }
        $now = $now ?? \core\di::get(\core\clock::class)->time();
        $ids = $DB->get_fieldset_sql(
            "SELECT e.id
               FROM {" . self::TABLE . "} e
          LEFT JOIN {" . merge_registry::TABLE . "} m ON m.fromuserid = e.fromuserid AND m.touserid = e.touserid
              WHERE e.timeacknowledged > 0 AND e.timeacknowledged < :cutoff
                AND (m.id IS NULL OR m.status = :reconciled)",
            ['cutoff' => $now - $days * DAYSECS, 'reconciled' => merge_registry::RECONCILED]
        );
        foreach (array_chunk($ids, 500) as $chunk) {
            $DB->delete_records_list(self::TABLE, 'id', $chunk);
        }
        return count($ids);
    }

    /**
     * Reduce archived rows of earlier versions to the positive list (upgrade).
     *
     * @return array<string, int> Changed records per table.
     */
    public static function sanitise_archive(): array {
        global $DB;
        $changed = [];
        $rs = $DB->get_recordset(self::TABLE, null, 'id ASC', 'id, tablename, keyfields, keyvalues, sourcerows, targetrows');
        foreach ($rs as $record) {
            $keyfields = array_filter(explode(',', (string) $record->keyfields));
            $update = ['id' => $record->id];
            foreach (['sourcerows', 'targetrows'] as $side) {
                $clean = json_encode(self::rows($record, $side));
                if ($record->$side !== null && $clean !== (string) $record->$side) {
                    $update[$side] = $clean;
                }
            }
            $values = json_decode((string) $record->keyvalues, true);
            if (is_array($values) && $keyfields) {
                $clean = json_encode(self::safe_keyvalues(array_values($keyfields), $values));
                if ($clean !== (string) $record->keyvalues) {
                    $update['keyvalues'] = $clean;
                }
            }
            if (count($update) > 1) {
                $DB->update_record(self::TABLE, (object) $update);
                $changed[$record->tablename] = ($changed[$record->tablename] ?? 0) + 1;
            }
        }
        $rs->close();
        return $changed;
    }

    /**
     * Forget the per-request caches (each merge, tests, long-running tasks).
     *
     * @return void
     */
    public static function reset_cache(): void {
        self::$involved = [];
        self::$usage = [];
        self::$columns = [];
    }

    /**
     * Forget a recorded refusal (after it was reported).
     *
     * @param int $fromuserid Source.
     * @param int $touserid Survivor.
     * @return void
     */
    public static function clear_refusal(int $fromuserid, int $touserid): void {
        unset(self::$refused[$fromuserid . '_' . $touserid]);
    }
}
