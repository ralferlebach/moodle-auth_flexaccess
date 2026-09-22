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

namespace auth_flexaccess;

/**
 * Event observers of auth_flexaccess.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class observer {
    /**
     * Finalise an account that was waiting for its credential once a password has been set.
     *
     * @param \core\event\user_password_updated $event Event.
     * @return void
     */
    public static function user_password_updated(\core\event\user_password_updated $event): void {
        $userid = (int) $event->relateduserid;
        if ($userid > 0) {
            api::finalise_pending_credential($userid);
        }
    }

    /**
     * Reconcile FlexAccess state after tool_mergeusers merged two identities.
     *
     * tool_mergeusers moves enrolments and role assignments by direct table updates; this pass lifts
     * the restriction the surviving identity may have inherited and removes the merged-away
     * FlexAccess metadata. Course access is not made permanent implicitly.
     *
     * @param \core\event\base $event Event carrying other['usersinvolved'] with toid/fromid.
     * @return void
     */
    public static function user_merged(\core\event\base $event): void {
        $involved = (array) ($event->other['usersinvolved'] ?? []);
        $to = (int) ($involved['toid'] ?? 0);
        $from = (int) ($involved['fromid'] ?? 0);
        if ($to > 0 && $from > 0) {
            api::reconcile_external_identity_merge($from, $to, false);
        }
    }
}
