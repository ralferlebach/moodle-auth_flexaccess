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
 * Which user tables a merge can resolve conflicts in, and how FlexAccess treats each (auth_flexaccess #13).
 *
 * tool_mergeusers moves every row with a user column to the survivor. Only where a unique or semantic key
 * makes two rows collide - its "compound indexes", plus the tables with a dedicated merger - does it keep one
 * row and delete, renumber or merge the other. Those are exactly the places where data can be lost, so the
 * list is read from the tool's own configuration (Core and third-party plugins it knows), not hard-coded.
 *
 * Every collision is archived before tool_mergeusers resolves it ({@see merge_evidence}). The rule per table:
 *  - benign ({@see self::BENIGN}): memberships, subscriptions, caches, devices, autosaves and similar - two
 *    rows mean the same fact; the archive is kept, no review is needed;
 *  - enrolment: FlexAccess enrolments, merged with FlexAccess' own rule ({@see merge_evidence::apply_enrolments()});
 *  - review: everything else, in particular grades, attempts, submissions, completion, competencies, policy
 *    acceptances and every table FlexAccess does not know - the merge stays under review until an
 *    administrator has checked the archived rows.
 * A table merger FlexAccess cannot wrap (an unknown dedicated merger, or a site setting that replaces
 * FlexAccess' mergers) is a coverage gap: merges involving FlexAccess are then refused ({@see self::gaps()}).
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class merge_coverage {
    /** Archived collision that needs an administrator's review. */
    public const KIND_REVIEW = 'review';
    /** Archived collision of two rows that mean the same fact. */
    public const KIND_BENIGN = 'benign';
    /** Archived FlexAccess enrolment, merged by FlexAccess' rule. */
    public const KIND_ENROLMENT = 'enrolment';

    /** Collisions of these tables lose no information about the person's work or entitlements. */
    public const BENIGN = [
        'auth_lti_linked_login', 'auth_oauth2_linked_login', 'badge_backpack', 'block_recentlyaccesseditems',
        'cohort_members', 'course_modules_viewed', 'editor_atto_autosave', 'favourite', 'forum_digests',
        'forum_discussion_subs', 'forum_subscriptions', 'groups_members', 'message_contact_requests',
        'message_contacts', 'message_user_actions', 'message_users_blocked', 'oauth2_refresh_token', 'post',
        'role_assignments', 'tag_instance', 'tiny_autosave', 'tool_cohortroles', 'tool_monitor_history',
        'user_devices', 'user_enrolments', 'user_lastaccess', 'assign_user_mapping',
    ];

    /** Keys used when tool_mergeusers is not installed (preview only) and for dedicated mergers without one. */
    private const FALLBACK = [
        'grade_grades' => [['userid'], ['itemid']],
        'quiz_attempts' => [['userid'], ['attempt', 'quiz']],
        'assign_submission' => [['userid'], ['assignment', 'attemptnumber', 'groupid']],
        'assign_grades' => [['userid'], ['assignment', 'attemptnumber']],
        'course_completions' => [['userid'], ['course']],
        'course_modules_completion' => [['userid'], ['coursemoduleid']],
        'h5pactivity_attempts' => [['userid'], ['attempt', 'h5pactivityid']],
        'choice_answers' => [['userid'], ['choiceid', 'optionid']],
        'lesson_attempts' => [['userid'], ['lessonid']],
    ];

    /** Dedicated tool_mergeusers mergers FlexAccess wraps: table => [parent class, FlexAccess class]. */
    public const WRAPPED = [
        'default' => ['generic_table_merger', 'default_merger'],
        'grade_grades' => ['grade_grades_table_merger', 'grade_grades_merger'],
        'quiz_attempts' => ['quiz_attempts_table_merger', 'quiz_attempts_merger'],
        'assign_submission' => ['assign_submission_table_merger', 'assign_submission_merger'],
        'choice_answers' => ['choice_answers_table_merger', 'choice_answers_merger'],
        'lesson_attempts' => ['lesson_attempts_table_merger', 'lesson_attempts_merger'],
    ];

    /**
     * How FlexAccess treats a collision in a table.
     *
     * @param string $table Table name.
     * @return string One of the KIND_* constants (the enrolment kind is assigned by the enrolment capture).
     */
    public static function kind(string $table): string {
        return in_array($table, self::BENIGN, true) ? self::KIND_BENIGN : self::KIND_REVIEW;
    }

    /**
     * Kind of one collision: like {@see self::kind()}, except that a grade collision only needs a review when
     * both users have a final grade (otherwise tool_mergeusers keeps the only grade there is).
     *
     * @param string $table Table name.
     * @param \stdClass[] $source The source's colliding rows.
     * @param \stdClass[] $target The survivor's colliding rows.
     * @return string KIND_REVIEW or KIND_BENIGN.
     */
    public static function collision_kind(string $table, array $source, array $target): string {
        if ($table === 'grade_grades') {
            $graded = static fn(array $rows): bool => (bool) array_filter($rows, static fn($r): bool => $r->finalgrade !== null);
            return $graded($source) && $graded($target) ? self::KIND_REVIEW : self::KIND_BENIGN;
        }
        return self::kind($table);
    }

    /**
     * Collision key of a table handled by a dedicated tool_mergeusers merger without a configured key.
     *
     * @param string $table Table name.
     * @return array|null [user fields, key fields] or null.
     */
    public static function dedicated_key(string $table): ?array {
        return isset(self::WRAPPED[$table]) && $table !== 'default' ? (self::FALLBACK[$table] ?? null) : null;
    }

    /**
     * Whether tool_mergeusers with its configuration API is installed.
     *
     * @return bool
     */
    public static function mergeusers_available(): bool {
        return class_exists('\tool_mergeusers\local\config');
    }

    /**
     * Collision keys of every table: table => [user fields, key fields].
     *
     * Read from tool_mergeusers' effective configuration (its defaults, hooks and site settings); entries
     * without key fields (pairs of users such as contacts) are not collisions of one person's data.
     * Dedicated mergers without a configured key use FlexAccess' fallback key.
     *
     * @return array<string, array{0: string[], 1: string[]}>
     */
    public static function keys(): array {
        global $DB;
        $out = [];
        if (self::mergeusers_available()) {
            foreach ((array) \tool_mergeusers\local\config::instance()->compoundindexes as $table => $definition) {
                if (!is_array($definition) || empty($definition['userfield']) || empty($definition['otherfields'])) {
                    continue;
                }
                $out[$table] = [array_values($definition['userfield']), array_values($definition['otherfields'])];
            }
        }
        foreach (self::FALLBACK as $table => $definition) {
            $out[$table] = $out[$table] ?? $definition;
        }
        $manager = $DB->get_manager();
        return array_filter($out, static fn(string $table): bool => $manager->table_exists($table), ARRAY_FILTER_USE_KEY);
    }

    /**
     * Table mergers in tool_mergeusers' effective configuration that FlexAccess does not wrap.
     *
     * @return string[] table => merger class, for each gap.
     */
    public static function gaps(): array {
        if (!self::mergeusers_available()) {
            return [];
        }
        $gaps = [];
        foreach ((array) \tool_mergeusers\local\config::instance()->tablemergers as $table => $class) {
            $class = ltrim(is_object($class) ? get_class($class) : (string) $class, '\\');
            if (strpos($class, 'auth_flexaccess\\local\\mergeusers\\') !== 0) {
                $gaps[$table] = $class;
            }
        }
        return $gaps;
    }

    /**
     * The tool_mergeusers settings that make FlexAccess archive collisions first (for the settings hook).
     *
     * @return array<string, string> table => FlexAccess merger class, only where the parent class exists.
     */
    public static function mergers(): array {
        $mergers = [];
        foreach (self::WRAPPED as $table => [$parent, $own]) {
            if (class_exists('\\tool_mergeusers\\local\\merger\\' . $parent)) {
                $mergers[$table] = '\\auth_flexaccess\\local\\mergeusers\\' . $own;
            }
        }
        return $mergers;
    }
}
