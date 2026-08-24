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

use auth_flexaccess\privacy\provider;
use auth_flexaccess\local\account_service;
use core_privacy\local\request\writer;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;

/**
 * Tests for the auth_flexaccess privacy provider.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \auth_flexaccess\privacy\provider
 */
final class privacy_provider_test extends \core_privacy\tests\provider_testcase {
    /**
     * Create a user with a FlexAccess account, a token, a queued mail and a pending-email preference.
     *
     * @return int User id.
     */
    private function user_with_data(): int {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $userid = (int) $user->id;
        account_service::create_temporary($userid, '4242424242', time() + DAYSECS);
        local\token_service::issue($userid, 'persistence', 900);
        \auth_flexaccess\api::queue_mail($userid, 'pending@example.com', 'Subject', 'Body');
        set_user_preference('auth_flexaccess_pendingemail', 'pending@example.com', $userid);
        return $userid;
    }

    /**
     * The metadata collection describes every owned table and the pending-email preference.
     *
     * @return void
     */
    public function test_get_metadata(): void {
        $collection = new \core_privacy\local\metadata\collection('auth_flexaccess');
        $collection = provider::get_metadata($collection);
        $items = $collection->get_collection();
        $names = [];
        foreach ($items as $item) {
            $names[] = $item->get_name();
        }
        $this->assertContains('auth_flexaccess_account', $names);
        $this->assertContains('auth_flexaccess_token', $names);
        $this->assertContains('auth_flexaccess_mailqueue', $names);
        $this->assertContains('auth_flexaccess_pendingemail', $names);
    }

    /**
     * The user's context is discovered from either a table row or the preference.
     *
     * @return void
     */
    public function test_get_contexts_for_userid(): void {
        $this->resetAfterTest();
        $userid = $this->user_with_data();
        $contexts = array_map('intval', provider::get_contexts_for_userid($userid)->get_contextids());
        $this->assertContains(\context_user::instance($userid)->id, $contexts);
    }

    /**
     * Export includes the table rows and the pending-email preference.
     *
     * @return void
     */
    public function test_export(): void {
        $this->resetAfterTest();
        $userid = $this->user_with_data();
        $context = \context_user::instance($userid);

        $this->export_context_data_for_user($userid, $context, 'auth_flexaccess');
        provider::export_user_preferences($userid);
        $writer = writer::with_context($context);
        $this->assertTrue($writer->has_any_data());

        $prefs = $writer->get_user_preferences('auth_flexaccess');
        $this->assertObjectHasProperty('auth_flexaccess_pendingemail', $prefs);
        $this->assertSame('pending@example.com', $prefs->auth_flexaccess_pendingemail->value);
    }

    /**
     * Deleting the user's data removes all rows and the pending-email preference.
     *
     * @return void
     */
    public function test_delete_for_user(): void {
        global $DB;
        $this->resetAfterTest();
        $userid = $this->user_with_data();
        $context = \context_user::instance($userid);

        $approved = new approved_contextlist(\core_user::get_user($userid), 'auth_flexaccess', [$context->id]);
        provider::delete_data_for_user($approved);

        $this->assertEquals(0, $DB->count_records('auth_flexaccess_account', ['userid' => $userid]));
        $this->assertEquals(0, $DB->count_records('auth_flexaccess_token', ['userid' => $userid]));
        $this->assertEquals(0, $DB->count_records('auth_flexaccess_mailqueue', ['userid' => $userid]));
        $this->assertNull(get_user_preferences('auth_flexaccess_pendingemail', null, $userid));
    }

    /**
     * Deleting all users in a context removes that user's data and preference.
     *
     * @return void
     */
    public function test_delete_for_all_users_in_context(): void {
        global $DB;
        $this->resetAfterTest();
        $userid = $this->user_with_data();
        provider::delete_data_for_all_users_in_context(\context_user::instance($userid));

        $this->assertEquals(0, $DB->count_records('auth_flexaccess_account', ['userid' => $userid]));
        $this->assertNull(get_user_preferences('auth_flexaccess_pendingemail', null, $userid));
    }

    /**
     * The user is discovered within their own user context.
     *
     * @return void
     */
    public function test_get_users_in_context(): void {
        $this->resetAfterTest();
        $userid = $this->user_with_data();
        $context = \context_user::instance($userid);
        $userlist = new \core_privacy\local\request\userlist($context, 'auth_flexaccess');
        provider::get_users_in_context($userlist);
        $this->assertContains($userid, array_map('intval', $userlist->get_userids()));
    }

    /**
     * The userlist delete path removes an approved user's rows and preference within a context.
     *
     * @return void
     */
    public function test_delete_for_users(): void {
        global $DB;
        $this->resetAfterTest();
        $userid = $this->user_with_data();
        $context = \context_user::instance($userid);

        $approved = new approved_userlist($context, 'auth_flexaccess', [$userid]);
        provider::delete_data_for_users($approved);

        $this->assertEquals(0, $DB->count_records('auth_flexaccess_account', ['userid' => $userid]));
        $this->assertEquals(0, $DB->count_records('auth_flexaccess_token', ['userid' => $userid]));
        $this->assertEquals(0, $DB->count_records('auth_flexaccess_mailqueue', ['userid' => $userid]));
        $this->assertNull(get_user_preferences('auth_flexaccess_pendingemail', null, $userid));
    }
}
