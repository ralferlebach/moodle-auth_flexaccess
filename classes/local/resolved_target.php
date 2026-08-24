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
 * Immutable value object describing a resolved FlexAccess access target.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class resolved_target {
    /** @var int Course id. */
    public readonly int $courseid;

    /** @var int Course module id, or 0 when the target is the course itself. */
    public readonly int $cmid;

    /** @var string The validated local redirect URL (relative to wwwroot). */
    public readonly string $localurl;

    /**
     * Constructor.
     *
     * @param int $courseid Course id.
     * @param int $cmid Course module id (0 for a course-level target).
     * @param string $localurl Validated local redirect URL.
     */
    public function __construct(int $courseid, int $cmid, string $localurl) {
        $this->courseid = $courseid;
        $this->cmid = $cmid;
        $this->localurl = $localurl;
    }

    /**
     * The safe redirect URL to send the user to after access is granted.
     *
     * @return \moodle_url
     */
    public function redirect_url(): \moodle_url {
        return new \moodle_url($this->localurl);
    }
}
