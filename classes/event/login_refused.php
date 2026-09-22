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
 * A FlexAccess login was refused by the central login guard.
 *
 * Carries the precise, internal reason (suspended, expired, inactive, deleted, state mismatch, ...),
 * which the public login response deliberately does not reveal.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class login_refused extends \core\event\base {
    /**
     * Initialise the event data.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventloginrefused', 'auth_flexaccess');
    }

    /**
     * Non-localised description for the log.
     *
     * @return string
     */
    public function get_description() {
        $channel = s((string) ($this->other['channel'] ?? ''));
        $reason = s((string) ($this->other['reason'] ?? ''));
        $user = $this->relateduserid ? "'{$this->relateduserid}'" : 'unknown';
        return "The FlexAccess {$channel} login of the user with id {$user} was refused (reason: {$reason}).";
    }

    /**
     * Validate the event data.
     *
     * @return void
     */
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->other['channel']) || !isset($this->other['reason'])) {
            throw new \coding_exception('The \'channel\' and \'reason\' values must be set in other.');
        }
    }

    /**
     * No other-field mapping is needed for backup/restore.
     *
     * @return bool
     */
    public static function get_other_mapping() {
        return false;
    }
}
