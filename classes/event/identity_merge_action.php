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

namespace auth_flexaccess\event;

/**
 * An administrator acted on an identity merge or an LTI binding (approve, repoint, remove binding, re-check).
 *
 * other: action (approve|acknowledge|discardenrolment|repoint|removebinding|recheck), fromuserid, touserid, bindingid,
 * outcome; for approve and repoint also the evidence: approvedby, timeapproved, mergelogid (tool_mergeusers log record), reasons,
 * bindings, ltiusers, conflicts.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class identity_merge_action extends \core\event\base {
    /**
     * Initialise the event.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Localised name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventidentitymergeaction', 'auth_flexaccess');
    }

    /**
     * Non-localised description.
     *
     * @return string
     */
    public function get_description() {
        $action = s((string) ($this->other['action'] ?? ''));
        $from = (int) ($this->other['fromuserid'] ?? 0);
        $to = (int) ($this->other['touserid'] ?? 0);
        $outcome = s((string) ($this->other['outcome'] ?? ''));
        return "The user with id '{$this->userid}' performed '{$action}' on the identity merge {$from} -> {$to}: {$outcome}.";
    }

    /**
     * Validate the event data.
     *
     * @return void
     */
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->other['action'])) {
            throw new \coding_exception('The \'action\' value must be set in other.');
        }
    }

    /**
     * Mapping of other values (none map to restorable objects).
     *
     * @return bool
     */
    public static function get_other_mapping() {
        return false;
    }
}
