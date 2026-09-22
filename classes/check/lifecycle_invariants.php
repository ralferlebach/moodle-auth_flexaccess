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

namespace auth_flexaccess\check;

use core\check\check;
use core\check\result;

/**
 * Status check: FlexAccess accounts whose stores contradict the lifecycle invariants.
 *
 * Read-only. It surfaces existing inconsistencies (for example from data written before 1.1.0)
 * without changing anything; repairs are explicit actions in the FlexAccess system status.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class lifecycle_invariants extends check {
    /**
     * Short name of the check.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('checklifecycle', 'auth_flexaccess');
    }

    /**
     * Link to the FlexAccess system status, when the tool plugin provides it.
     *
     * @return \action_link|null
     */
    public function get_action_link(): ?\action_link {
        if (!class_exists('\tool_flexaccess\local\health')) {
            return null;
        }
        return new \action_link(
            new \moodle_url('/admin/tool/flexaccess/status.php'),
            get_string('checklifecycleaction', 'auth_flexaccess')
        );
    }

    /**
     * Evaluate the invariants.
     *
     * @return result
     */
    public function get_result(): result {
        $mismatches = \auth_flexaccess\local\lifecycle::find_state_mismatches(null, 1000);
        if (!$mismatches) {
            return new result(result::OK, get_string('checklifecycleok', 'auth_flexaccess'));
        }
        $counts = [];
        foreach ($mismatches as $m) {
            $counts[$m->code] = ($counts[$m->code] ?? 0) + 1;
        }
        $lines = [];
        foreach ($counts as $code => $n) {
            $lines[] = \html_writer::tag('li', get_string('mismatch_' . $code, 'auth_flexaccess') . ': ' . $n);
        }
        $critical = array_intersect(array_keys($counts), [
            \auth_flexaccess\local\lifecycle::MISMATCH_LOCKED_UNSUSPENDED,
            \auth_flexaccess\local\lifecycle::MISMATCH_TEMPORARY_UNRESTRICTED,
            \auth_flexaccess\local\lifecycle::MISMATCH_TYPE_STATE,
        ]);
        return new result(
            $critical ? result::ERROR : result::WARNING,
            get_string('checklifecyclemismatch', 'auth_flexaccess', count($mismatches)),
            \html_writer::tag('ul', implode('', $lines))
        );
    }
}
