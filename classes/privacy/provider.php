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

/**
 * Privacy provider for auth_flexaccess.
 *
 * FlexAccess stores per-user account metadata, one-time tokens and a mail queue, all keyed by
 * user id and therefore exported and deleted at the user context level.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace auth_flexaccess\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider implementation.
 */
final class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /** Tables owned by this plugin, all keyed by userid. */
    private const TABLES = ['auth_flexaccess_account', 'auth_flexaccess_token', 'auth_flexaccess_mailqueue'];

    /**
     * Describe the personal data stored by this plugin.
     *
     * @param collection $collection The metadata collection to populate.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('auth_flexaccess_account', [
            'userid' => 'privacy:metadata:account:userid',
            'accounttype' => 'privacy:metadata:account:accounttype',
            'accountstate' => 'privacy:metadata:account:accountstate',
            'referencecode' => 'privacy:metadata:account:referencecode',
        ], 'privacy:metadata:account');
        $collection->add_database_table('auth_flexaccess_token', [
            'userid' => 'privacy:metadata:token:userid',
            'purpose' => 'privacy:metadata:token:purpose',
        ], 'privacy:metadata:token');
        $collection->add_database_table('auth_flexaccess_mailqueue', [
            'userid' => 'privacy:metadata:mail:userid',
            'recipient' => 'privacy:metadata:mail:recipient',
            'mailtype' => 'privacy:metadata:mail:mailtype',
        ], 'privacy:metadata:mail');
        return $collection;
    }

    /**
     * Get the list of contexts that contain user information for the given user.
     *
     * @param int $userid The user id to search for.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $checks = [];
        $params = ['userlevel' => CONTEXT_USER, 'userid' => $userid];
        foreach (self::TABLES as $i => $table) {
            $checks[] = "EXISTS (SELECT 1 FROM {" . $table . "} t$i WHERE t$i.userid = ctx.instanceid)";
        }
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                 WHERE ctx.contextlevel = :userlevel
                   AND ctx.instanceid = :userid
                   AND (" . implode(' OR ', $checks) . ")";
        $contextlist->add_from_sql($sql, $params);
        return $contextlist;
    }

    /**
     * Get the list of users within a specific context.
     *
     * @param userlist $userlist The userlist to populate.
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof \context_user) {
            return;
        }
        foreach (self::TABLES as $table) {
            $userlist->add_from_sql(
                'userid',
                "SELECT userid FROM {" . $table . "} WHERE userid = :userid",
                ['userid' => $context->instanceid]
            );
        }
    }

    /**
     * Export all user data for the approved contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts to export.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_user) {
                continue;
            }
            $userid = $context->instanceid;
            foreach (self::TABLES as $table) {
                $records = $DB->get_records($table, ['userid' => $userid]);
                if ($records) {
                    writer::with_context($context)->export_data(
                        [get_string('pluginname', 'auth_flexaccess'), $table],
                        (object) ['records' => array_values($records)]
                    );
                }
            }
        }
    }

    /**
     * Delete all user data for all users in the given context.
     *
     * @param \context $context The context to delete data for.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        if (!$context instanceof \context_user) {
            return;
        }
        foreach (self::TABLES as $table) {
            $DB->delete_records($table, ['userid' => $context->instanceid]);
        }
    }

    /**
     * Delete all user data for the given user in the approved contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts and user to delete data for.
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_user) {
                continue;
            }
            foreach (self::TABLES as $table) {
                $DB->delete_records($table, ['userid' => $context->instanceid]);
            }
        }
    }

    /**
     * Delete data for multiple users within a single context.
     *
     * @param approved_userlist $userlist The approved users and context to delete data for.
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;
        $context = $userlist->get_context();
        if (!$context instanceof \context_user) {
            return;
        }
        if (in_array($context->instanceid, $userlist->get_userids())) {
            foreach (self::TABLES as $table) {
                $DB->delete_records($table, ['userid' => $context->instanceid]);
            }
        }
    }
}
