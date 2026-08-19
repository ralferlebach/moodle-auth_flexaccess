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
 * Single-use, hashed token service for FlexAccess links.
 *
 * Tokens are cryptographically random and time-limited. Only the SHA-256 hash of a token is
 * persisted; the clear-text value is returned once to the caller (typically the mail worker
 * immediately before sending) and never stored or logged. Verification looks tokens up by
 * hash, and consumption is single-use within a transaction.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace auth_flexaccess\local;

/**
 * Issues, verifies and consumes one-time tokens.
 *
 * @package    auth_flexaccess
 */
final class token_service {
    /**
     * Token table.
     */
    private const TABLE = 'auth_flexaccess_token';
    /**
     * Default time-to-live in seconds.
     */
    public const DEFAULT_TTL = 86400;

    /**
     * Issue a new single-use token and return its clear-text value.
     *
     * @param int $userid User the token authorises.
     * @param string $purpose Token purpose (e.g. activation, persistence, delete).
     * @param int|null $ttl Time-to-live in seconds.
     * @param int|null $now Current time.
     * @return string Clear-text token; store or transmit it, but never log it.
     */
    public static function issue(int $userid, string $purpose, ?int $ttl = null, ?int $now = null): string {
        global $DB;
        $now = $now ?? time();
        $ttl = $ttl ?? self::DEFAULT_TTL;
        $secret = bin2hex(random_bytes(32));
        $DB->insert_record(self::TABLE, (object) [
            'userid' => $userid,
            'purpose' => $purpose,
            'tokenhash' => self::hash($secret),
            'timecreated' => $now,
            'timeexpires' => $now + max(1, $ttl),
            'timeused' => null,
        ]);
        return $secret;
    }

    /**
     * Revoke all unused tokens for a user and purpose.
     *
     * Called immediately before issuing a fresh token so that a delivery retry never leaves more
     * than one live secret for the same purpose.
     *
     * @param int $userid User id.
     * @param string $purpose Token purpose.
     * @return void
     */
    public static function revoke_pending(int $userid, string $purpose): void {
        global $DB;
        $DB->delete_records_select(
            self::TABLE,
            'userid = :userid AND purpose = :purpose AND timeused IS NULL',
            ['userid' => $userid, 'purpose' => $purpose]
        );
    }

    /**
     * Verify a token without consuming it.
     *
     * @param string $secret Clear-text token supplied by the user.
     * @param string $purpose Expected purpose.
     * @param int|null $now Current time.
     * @return \stdClass|null The token record when valid, otherwise null.
     */
    public static function verify(string $secret, string $purpose, ?int $now = null): ?\stdClass {
        global $DB;
        if ($secret === '') {
            return null;
        }
        $now = $now ?? time();
        $record = $DB->get_record(self::TABLE, [
            'tokenhash' => self::hash($secret),
            'purpose' => $purpose,
        ]);
        if (!$record) {
            return null;
        }
        if ($record->timeused !== null) {
            return null;
        }
        if ((int) $record->timeexpires <= $now) {
            return null;
        }
        return $record;
    }

    /**
     * Consume a token single-use and return the authorised user id.
     *
     * @param string $secret Clear-text token supplied by the user.
     * @param string $purpose Expected purpose.
     * @param int|null $now Current time.
     * @param int|null $expecteduserid If set, the token is only consumed when it belongs to this user.
     * @return int|null The user id on success, otherwise null.
     */
    public static function consume(
        string $secret,
        string $purpose,
        ?int $now = null,
        ?int $expecteduserid = null
    ): ?int {
        global $DB;
        $now = $now ?? time();
        // Serialise consumption per token hash so two concurrent requests cannot both accept the same
        // single-use token (portable Moodle Lock API instead of a DB-specific SELECT ... FOR UPDATE).
        $lockfactory = \core\lock\lock_config::get_lock_factory('auth_flexaccess_token');
        $lock = $lockfactory->get_lock(self::hash($secret), 10);
        if (!$lock) {
            return null;
        }
        try {
            $record = self::verify($secret, $purpose, $now);
            if (!$record) {
                return null;
            }
            // Never burn a token that belongs to a different user.
            if ($expecteduserid !== null && (int) $record->userid !== $expecteduserid) {
                return null;
            }
            $record->timeused = $now;
            $DB->update_record(self::TABLE, $record);
            return (int) $record->userid;
        } finally {
            $lock->release();
        }
    }

    /**
     * Hash a clear-text token for storage and lookup.
     *
     * @param string $secret Clear-text token.
     * @return string
     */
    private static function hash(string $secret): string {
        return hash('sha256', $secret);
    }
}
