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
 * Privacy provider scaffold for auth_flexaccess.
 *
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace auth_flexaccess\privacy;

/** Privacy metadata provider. */
final class provider implements \core_privacy\local\metadata\provider {
    /**
     * Describe stored personal data.
     *
     * @param \core_privacy\local\metadata\collection $collection Collection.
     * @return \core_privacy\local\metadata\collection
     */
    public static function get_metadata(
            \core_privacy\local\metadata\collection $collection): \core_privacy\local\metadata\collection {
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
}
