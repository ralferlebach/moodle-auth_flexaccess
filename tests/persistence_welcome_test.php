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
 * After persistence a user must be able to actually use their account.
 *
 * They never saw the generated username, so without a welcome mail they cannot log in again; and
 * the account must support the standard password change/recovery, otherwise a fully-fledged account
 * would be unmanageable by its own owner.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\auth_flexaccess\local\persistence_service::class)]
final class persistence_welcome_test extends \advanced_testcase {
    public function test_flexaccess_accounts_support_password_change_and_recovery(): void {
        $this->resetAfterTest();
        $auth = get_auth_plugin('flexaccess');

        // Without these, Moodle refuses password changes and tells the user to contact an admin.
        $this->assertTrue($auth->can_change_password());
        $this->assertTrue($auth->can_reset_password());
        $this->assertTrue($auth->is_internal());
        $this->assertNull($auth->change_password_url());
    }

    public function test_welcome_mail_carries_username_and_login_url_but_no_password(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $now = time();
        $userid = \auth_flexaccess\api::create_temporary_user($now + DAYSECS, (int) $course->id, null, $now);
        $this->assertGreaterThan(0, $userid);

        set_config('requireemailverification', 0, 'auth_flexaccess');
        $password = 'Chosen-Pass!1';
        $status = \auth_flexaccess\api::persist_temporary_user(
            $userid,
            'real.person@example.com',
            'Ralf',
            'Erlebach',
            $password
        );
        $this->assertSame('converted', $status);

        $sink = $this->redirectEmails();
        \auth_flexaccess\local\mail_worker::run(time());
        $messages = $sink->get_messages();
        $sink->close();

        $welcome = null;
        foreach ($messages as $message) {
            if (strpos((string) $message->to, 'real.person@example.com') !== false) {
                $welcome = $message;
            }
        }
        $this->assertNotNull($welcome, 'No mail was sent to the newly persisted user.');
        $body = quoted_printable_decode((string) $welcome->body);
        $username = $DB->get_field('user', 'username', ['id' => $userid]);

        // The username and where to log in - that is what the user is missing.
        $this->assertStringContainsString($username, $body);
        $this->assertStringContainsString($CFG->wwwroot . '/login/index.php', $body);
        // A recovery route, so a forgotten password is not a dead end.
        $this->assertStringContainsString('forgot_password.php', $body);
        // Never the password itself.
        $this->assertStringNotContainsString($password, $body);
    }
}
