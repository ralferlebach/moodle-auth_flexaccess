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
 * Resolves a Moodle "wantsurl" to the course (and, where applicable, the activity) it targets.
 *
 * FlexAccess deliberately sits in front of require_login(), so it must derive the access target
 * itself. This resolver accepts only safe, local course and activity view URLs and rejects
 * external or unrelated URLs, which both fixes the broken login-page link (a bare wantsurl could
 * not be consumed by access.php) and guards against open-redirect abuse.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class target_resolver {
    /**
     * Resolve a wantsurl into a course-bound target.
     *
     * @param string|null $wantsurl The raw wantsurl (may be empty).
     * @return \auth_flexaccess\local\resolved_target|null The target, or null when it is not a safe local course/activity URL.
     */
    public static function resolve(?string $wantsurl): ?resolved_target {
        if ($wantsurl === null || trim($wantsurl) === '') {
            return null;
        }

        // Must be a local URL; reject anything external outright.
        $clean = clean_param($wantsurl, PARAM_LOCALURL);
        if ($clean === '') {
            return null;
        }

        try {
            $local = (new \moodle_url($clean))->out_as_local_url(false);
        } catch (\moodle_exception $e) {
            return null;
        }

        $path = (string) parse_url($local, PHP_URL_PATH);
        $params = [];
        parse_str((string) parse_url($local, PHP_URL_QUERY), $params);
        $id = isset($params['id']) ? (int) $params['id'] : 0;
        if ($id <= 0) {
            return null;
        }

        // Activity deep link: /mod/<modname>/view.php?id=<cmid>.
        if (preg_match('#/mod/[^/]+/view\.php$#', $path)) {
            $cm = get_coursemodule_from_id('', $id, 0, false, IGNORE_MISSING);
            if ($cm && (int) $cm->course !== (int) SITEID) {
                return new resolved_target((int) $cm->course, (int) $cm->id, $clean);
            }
            return null;
        }

        // Course deep link: /course/view.php?id=<courseid>.
        if (preg_match('#/course/view\.php$#', $path)) {
            if ($id !== (int) SITEID && self::course_exists($id)) {
                return new resolved_target($id, 0, $clean);
            }
        }

        return null;
    }

    /**
     * Whether a course with the given id exists.
     *
     * @param int $courseid Course id.
     * @return bool
     */
    private static function course_exists(int $courseid): bool {
        global $DB;
        return $DB->record_exists('course', ['id' => $courseid]);
    }
}
