<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace auth_flexaccess;

/**
 * Hook callbacks for auth_flexaccess.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /**
     * Show temporary users a hint on how to make their access permanent (D2).
     *
     * Deliberately scoped: only on a real course page, only for FlexAccess temporary accounts, and
     * only in courses that actually contain a FlexAccess activity (mod_flexaccess) - so the prompt
     * appears where persisting progress is meaningful and nowhere else.
     *
     * @param \core\hook\output\before_standard_top_of_body_html_generation $hook The output hook.
     * @return void
     */
    public static function before_standard_top_of_body_html_generation(
        \core\hook\output\before_standard_top_of_body_html_generation $hook
    ): void {
        global $PAGE, $USER, $DB;

        if (!isloggedin() || isguestuser()) {
            return;
        }
        if (empty($PAGE->course) || (int) $PAGE->course->id <= SITEID) {
            return;
        }
        $courseid = (int) $PAGE->course->id;
        if (!$DB->record_exists('flexaccess', ['course' => $courseid])) {
            return;
        }
        if (!\auth_flexaccess\local\account_service::is_temporary((int) $USER->id)) {
            return;
        }

        $link = \html_writer::link(
            new \moodle_url('/auth/flexaccess/persist.php'),
            get_string('persisthintcta', 'auth_flexaccess'),
            ['class' => 'alert-link']
        );
        $hook->add_html(\html_writer::div(
            get_string('persisthinttext', 'auth_flexaccess') . ' ' . $link,
            'alert alert-info flexaccess-persist-hint'
        ));
    }

    /**
     * Re-check FlexAccess accounts signed in by a path other than FlexAccess' own (auth_lti #12).
     *
     * A bound LTI launch signs the user in with complete_user_login() after checking only the Moodle
     * suspension flag. FlexAccess' expiry, lifecycle state and credential rules are enforced here; a
     * refused session is ended at once and the request stops with an error - never as a guest.
     *
     * @param \core_user\hook\after_login_completed $hook The hook.
     * @return void
     * @throws \moodle_exception When the session was refused.
     */
    public static function after_login_completed(\core_user\hook\after_login_completed $hook): void {
        global $USER;
        if (empty($USER->id) || isguestuser()) {
            return;
        }
        $userid = (int) $USER->id;
        if (\auth_flexaccess\local\lti_guard::enforce_session($userid) !== '') {
            throw new \moodle_exception('externalloginrefused', 'auth_flexaccess', new \moodle_url('/login/index.php'));
        }
    }

    /**
     * Gate tool_mergeusers' merge from inside its transaction (auth_flexaccess #13).
     *
     * Runs after tool_mergeusers moved every table and before it commits. FlexAccess refuses the merge
     * when its policy does not allow it (a durable FlexAccess identity into a non-FlexAccess target, two
     * durable identities without approval), and when the result would leave LTI bindings or grade
     * delivery pointing at the merged-away user or duplicate a membership: an error message makes
     * tool_mergeusers roll the whole merge back. Otherwise FlexAccess reconciles its own state in the same
     * transaction and records the surviving identity's suspension, which tool_mergeusers lifts afterwards.
     *
     * @param \tool_mergeusers\hook\after_merged_all_tables $hook The hook.
     * @return void
     */
    public static function mergeusers_after_merged_all_tables($hook): void {
        $from = (int) $hook->fromid;
        $to = (int) $hook->toid;
        if ($from <= 0 || $to <= 0 || $from === $to) {
            return;
        }
        if (!\auth_flexaccess\local\merge_policy::involved($from, $to)) {
            // No FlexAccess state on either side: the general merge tool decides alone (auth_flexaccess #13).
            return;
        }
        // Inside tool_mergeusers' transaction: the merge is in progress, not confirmed (no log record yet).
        $registry = \auth_flexaccess\local\merge_registry::record(
            $from,
            $to,
            'hook',
            \auth_flexaccess\local\merge_registry::MERGE_IN_PROGRESS
        );
        \auth_flexaccess\local\merge_registry::snapshot_target($registry, $to);
        $result = \auth_flexaccess\local\merge_service::reconcile($from, $to, false, null, 'hook');
        if ($result->status === 'locked') {
            // Another reconciliation of the pair is running: never commit a merge FlexAccess did not check.
            $hook->add_error_message(get_string('mergerefused_locked', 'auth_flexaccess'));
            return;
        }
        $blocking = array_intersect($result->reasons, \auth_flexaccess\local\merge_guard::BLOCKING_POSTCONDITIONS);
        if ($result->status === \auth_flexaccess\local\merge_registry::REFUSED || $blocking) {
            foreach ($result->status === \auth_flexaccess\local\merge_registry::REFUSED ? $result->reasons : $blocking as $reason) {
                $hook->add_error_message(get_string('mergerefused_' . $reason, 'auth_flexaccess'));
            }
            return;
        }
        $hook->add_log(get_string('mergereconciled', 'auth_flexaccess', (object) [
            'status' => $result->status,
            'reasons' => $result->reasons ? implode(', ', $result->reasons) : '-',
        ]));
    }

    /**
     * Keep tool_mergeusers' generic table merge away from FlexAccess' own identity tables.
     *
     * The account lifecycle row, tokens, queued mail and the merge registry describe one identity each;
     * moving them onto the surviving user would blur which identity was durable and break the policy
     * check that runs before the merge commits. FlexAccess reconciles them itself
     * ({@see self::mergeusers_after_merged_all_tables()}). String keys are deliberate: tool_mergeusers
     * combines settings with array_replace_recursive(), which would let numeric keys overwrite its own
     * default exceptions.
     *
     * @param \tool_mergeusers\hook\add_settings_before_merging $hook The hook.
     * @return void
     */
    public static function mergeusers_add_settings($hook): void {
        $tables = [
            'auth_flexaccess_account',
            'auth_flexaccess_token',
            'auth_flexaccess_mailqueue',
            'auth_flexaccess_merge',
            \auth_flexaccess\local\merge_evidence::TABLE,
        ];
        $hook->add_setting('exceptions', array_combine($tables, $tables));
        // Every colliding row of both users is archived right before tool_mergeusers resolves it
        // (auth_flexaccess #13): FlexAccess wraps the default merger and each dedicated merger it knows. A new
        // merge starts with a fresh involvement cache.
        \auth_flexaccess\local\merge_evidence::reset_cache();
        $mergers = \auth_flexaccess\local\merge_coverage::mergers();
        if ($mergers) {
            $hook->add_setting('tablemergers', $mergers);
        }
    }
}
