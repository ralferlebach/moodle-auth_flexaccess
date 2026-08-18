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
 * Tests for the FlexAccess token service.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace auth_flexaccess;

use auth_flexaccess\local\token_service;

/**
 * Token service tests.
 *
 * @package    auth_flexaccess
 * @covers     \auth_flexaccess\local\token_service
 */
final class token_service_test extends \advanced_testcase {
    /**
     * Only the hash is stored, never the clear-text token.
     */
    public function test_only_hash_is_stored(): void {
        global $DB;
        $this->resetAfterTest();
        $secret = token_service::issue(42, 'activation');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $secret);
        $row = $DB->get_record('auth_flexaccess_token', ['userid' => 42], '*', MUST_EXIST);
        $this->assertNotSame($secret, $row->tokenhash);
        $this->assertSame(hash('sha256', $secret), $row->tokenhash);
    }

    /**
     * A valid token verifies for the right purpose and rejects a wrong purpose.
     */
    public function test_verify_purpose(): void {
        $this->resetAfterTest();
        $secret = token_service::issue(7, 'persistence');
        $this->assertNotNull(token_service::verify($secret, 'persistence'));
        $this->assertNull(token_service::verify($secret, 'activation'));
        $this->assertNull(token_service::verify('bogus', 'persistence'));
        $this->assertNull(token_service::verify('', 'persistence'));
    }

    /**
     * An expired token does not verify.
     */
    public function test_expired_token(): void {
        $this->resetAfterTest();
        $now = 1000000;
        $secret = token_service::issue(7, 'persistence', 100, $now);
        $this->assertNotNull(token_service::verify($secret, 'persistence', $now + 50));
        $this->assertNull(token_service::verify($secret, 'persistence', $now + 100));
        $this->assertNull(token_service::verify($secret, 'persistence', $now + 200));
    }

    /**
     * Consuming a token is single-use and returns the user id.
     */
    public function test_consume_is_single_use(): void {
        $this->resetAfterTest();
        $secret = token_service::issue(99, 'delete');
        $this->assertSame(99, token_service::consume($secret, 'delete'));
        $this->assertNull(token_service::consume($secret, 'delete'));
        $this->assertNull(token_service::verify($secret, 'delete'));
    }
}
