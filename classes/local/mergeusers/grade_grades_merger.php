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

/**
 * tool_mergeusers table merger that archives colliding rows before resolving them (auth_flexaccess #13).
 *
 * Registered through the add_settings_before_merging hook only when tool_mergeusers provides the parent
 * class; it behaves exactly like the parent after recording the evidence.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grade_grades_merger extends \tool_mergeusers\local\merger\grade_grades_table_merger {
    use evidence_capture;
}
