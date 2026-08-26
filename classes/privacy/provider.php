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
 *
 * @package    auth_flexaccess
 */
final class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\user_preference_provider {
    /** Tables owned by this plugin, all keyed by userid. */
    private const TABLES = ['auth_flexaccess_account', 'auth_flexaccess_token', 'auth_flexaccess_mailqueue'];

    /** User preference holding a pending (unverified) email address during persistence. */
    private const PENDING_PREF = 'auth_flexaccess_pendingemail';

    /** User preference recording that a persistence follow-up reminder has been sent. */
    private const FOLLOWUP_PREF = 'auth_flexaccess_followupsent';

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
            'sourcecourseid' => 'privacy:metadata:account:sourcecourseid',
            'sourcecmid' => 'privacy:metadata:account:sourcecmid',
            'timecreated' => 'privacy:metadata:account:timecreated',
            'timeexpires' => 'privacy:metadata:account:timeexpires',
        ], 'privacy:metadata:account');
        $collection->add_database_table('auth_flexaccess_token', [
            'userid' => 'privacy:metadata:token:userid',
            'purpose' => 'privacy:metadata:token:purpose',
            'tokenhash' => 'privacy:metadata:token:tokenhash',
            'timecreated' => 'privacy:metadata:token:timecreated',
            'timeexpires' => 'privacy:metadata:token:timeexpires',
            'timeused' => 'privacy:metadata:token:timeused',
        ], 'privacy:metadata:token');
        $collection->add_database_table('auth_flexaccess_mailqueue', [
            'userid' => 'privacy:metadata:mail:userid',
            'recipient' => 'privacy:metadata:mail:recipient',
            'mailtype' => 'privacy:metadata:mail:mailtype',
            'payloadjson' => 'privacy:metadata:mail:payloadjson',
            'status' => 'privacy:metadata:mail:status',
            'timecreated' => 'privacy:metadata:mail:timecreated',
        ], 'privacy:metadata:mail');
        // Rate-limit telemetry. The actor (client address or email) is stored only as an HMAC with
        // a site secret, so a row cannot be traced back to a person and cannot be exported for a
        // subject access request - but it is declared here because it derives from personal data,
        // and it is purged with the rest of the plugin's data.
        $collection->add_database_table('auth_flexaccess_ratehit', [
            'bucket' => 'privacy:metadata:ratehit:bucket',
            'identifier' => 'privacy:metadata:ratehit:identifier',
            'timecreated' => 'privacy:metadata:ratehit:timecreated',
        ], 'privacy:metadata:ratehit');

        $collection->add_user_preference(self::PENDING_PREF, 'privacy:metadata:preference:pendingemail');
        $collection->add_user_preference(self::FOLLOWUP_PREF, 'privacy:metadata:preference:followupsent');
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
        $checks[] = "EXISTS (SELECT 1 FROM {user_preferences} up
                              WHERE up.userid = ctx.instanceid AND up.name = :pref)";
        $params['pref'] = self::PENDING_PREF;
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
     * Export the pending-email preference for a user.
     *
     * @param int $userid The user id to export preferences for.
     * @return void
     */
    public static function export_user_preferences(int $userid): void {
        $pending = get_user_preferences(self::PENDING_PREF, null, $userid);
        if ($pending !== null && $pending !== '') {
            writer::export_user_preference(
                'auth_flexaccess',
                self::PENDING_PREF,
                $pending,
                get_string('privacy:metadata:preference:pendingemail', 'auth_flexaccess')
            );
        }
        $followup = get_user_preferences(self::FOLLOWUP_PREF, null, $userid);
        if ($followup !== null && $followup !== '') {
            writer::export_user_preference(
                'auth_flexaccess',
                self::FOLLOWUP_PREF,
                $followup,
                get_string('privacy:metadata:preference:followupsent', 'auth_flexaccess')
            );
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
        unset_user_preference(self::PENDING_PREF, $context->instanceid);
        unset_user_preference(self::FOLLOWUP_PREF, $context->instanceid);
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
            unset_user_preference(self::PENDING_PREF, $context->instanceid);
            unset_user_preference(self::FOLLOWUP_PREF, $context->instanceid);
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
            unset_user_preference(self::PENDING_PREF, $context->instanceid);
            unset_user_preference(self::FOLLOWUP_PREF, $context->instanceid);
        }
    }
}
