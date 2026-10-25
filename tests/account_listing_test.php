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
 * Cursor listing of account ids honours its batch size.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace auth_flexaccess;

use auth_flexaccess\local\account_service;

/**
 * api::list_account_userids() returns at most $limit ids after the cursor, in id order.
 *
 * Regression: the limit was passed to get_fieldset_sql(), which has no limit parameters, so every
 * "batch" of a maintenance run silently contained all accounts.
 *
 * @package    auth_flexaccess
 * @covers \auth_flexaccess\api::list_account_userids
 */
final class account_listing_test extends \advanced_testcase {
    /**
     * Batches of two over five accounts: 2, 2, 1, then nothing.
     */
    public function test_cursor_batches_respect_limit(): void {
        $this->resetAfterTest();
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $userid = (int) $this->getDataGenerator()->create_user(['auth' => 'flexaccess'])->id;
            account_service::create_authenticated($userid, account_service::generate_unique_reference());
            $ids[] = $userid;
        }
        sort($ids);
        $cursor = 0;
        $batches = [];
        while ($batch = api::list_account_userids($cursor, 2)) {
            $batches[] = $batch;
            $cursor = (int) end($batch);
        }
        $this->assertSame([array_slice($ids, 0, 2), array_slice($ids, 2, 2), array_slice($ids, 4, 1)], $batches);
        $this->assertSame([$ids[0]], api::list_account_userids(0, 0), 'A limit below 1 still returns one id.');
    }
}
