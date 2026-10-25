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

namespace auth_flexaccess\local\mergeusers;

use auth_flexaccess\local\merge_coverage;
use auth_flexaccess\local\merge_evidence;

/**
 * Archive the colliding rows of a table, then let tool_mergeusers merge it as usual.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait evidence_capture {
    /**
     * Merge one table: archive the collisions of both users, then the parent's merge.
     *
     * @param array $data tableName, fromid, toid, the user fields and (if any) the compound index of the table.
     * @param array $logs Merge log lines.
     * @param array $errors Merge errors.
     * @return void
     */
    public function merge($data, &$logs, &$errors): void {
        $table = (string) ($data['tableName'] ?? '');
        $from = (int) ($data['fromid'] ?? 0);
        $to = (int) ($data['toid'] ?? 0);
        $recorded = 0;
        if ($table === merge_evidence::ENROLMENTS) {
            $recorded += merge_evidence::capture_enrolments($from, $to);
        }
        $index = $data['compoundIndex'] ?? null;
        if (is_array($index) && !empty($index['userfield']) && !empty($index['otherfields'])) {
            $userfields = array_values(array_intersect((array) $index['userfield'], (array) ($data['userFields'] ?? [])));
            $recorded += merge_evidence::capture_table($table, $userfields, array_values($index['otherfields']), $from, $to);
        } else if ($key = merge_coverage::dedicated_key($table)) {
            // A dedicated merger without a configured key (lesson attempts).
            $recorded += merge_evidence::capture_table($table, $key[0], $key[1], $from, $to);
        }
        if ($recorded > 0) {
            $logs[] = get_string('mergeevidencecaptured', 'auth_flexaccess', (object) ['table' => $table, 'count' => $recorded]);
        }
        parent::merge($data, $logs, $errors);
    }
}
