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
 * Atomic, cluster-safe sliding-window action rate limiter.
 *
 * Each action is a durable row in {@see self::TABLE}, so concurrent requests never lose increments
 * the way a cache read-modify-write can, and the counter is shared across all nodes of a Moodle
 * cluster through the database. The preferred entry point is {@see self::hit()}, which records the
 * action and re-reads the window in one step so the decision is race-free.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class rate_limiter {
    /**
     * Backing table.
     */
    private const TABLE = 'auth_flexaccess_ratehit';

    /**
     * Atomically record an action and report whether the identifier is now over the limit.
     *
     * The row is inserted first and the window is counted including it, so parallel requests cannot
     * slip past by observing a stale count. Exactly $max actions are allowed within the window.
     *
     * @param string $bucket Logical action name (e.g. 'quickreg', 'temp_ip').
     * @param string $identifier Opaque per-actor identifier (e.g. client address or email).
     * @param int $max Maximum actions allowed within the window.
     * @param int $window Sliding window length in seconds.
     * @param int|null $now Current time.
     * @return bool True when the limit has been exceeded (the action should be refused).
     */
    public static function hit(string $bucket, string $identifier, int $max, int $window, ?int $now = null): bool {
        $now = $now ?? time();
        self::insert($bucket, $identifier, $now);
        return self::count($bucket, $identifier, $window, $now) > $max;
    }

    /**
     * Whether the identifier has reached the limit within the window (read-only, does not record).
     *
     * @param string $bucket Logical action name.
     * @param string $identifier Opaque per-actor identifier.
     * @param int $max Maximum actions allowed within the window.
     * @param int $window Sliding window length in seconds.
     * @param int|null $now Current time.
     * @return bool
     */
    public static function too_many(
        string $bucket,
        string $identifier,
        int $max,
        int $window,
        ?int $now = null
    ): bool {
        $now = $now ?? time();
        return self::count($bucket, $identifier, $window, $now) >= $max;
    }

    /**
     * Record one action for the identifier.
     *
     * @param string $bucket Logical action name.
     * @param string $identifier Opaque per-actor identifier.
     * @param int|null $now Current time.
     * @return void
     */
    public static function record(string $bucket, string $identifier, ?int $now = null): void {
        self::insert($bucket, $identifier, $now ?? time());
    }

    /**
     * Clear all hits for an identifier.
     *
     * @param string $bucket Logical action name.
     * @param string $identifier Opaque per-actor identifier.
     * @return void
     */
    public static function reset(string $bucket, string $identifier): void {
        global $DB;
        $DB->delete_records(self::TABLE, ['bucket' => $bucket, 'identifier' => self::hash($identifier)]);
    }

    /**
     * Delete hit rows older than the given time (housekeeping).
     *
     * @param int $olderthan Delete rows with timecreated below this value.
     * @return void
     */
    public static function prune(int $olderthan): void {
        global $DB;
        $DB->delete_records_select(self::TABLE, 'timecreated < :cutoff', ['cutoff' => $olderthan]);
    }

    /**
     * Insert a single hit row.
     *
     * @param string $bucket Logical action name.
     * @param string $identifier Opaque per-actor identifier.
     * @param int $now Current time.
     * @return void
     */
    private static function insert(string $bucket, string $identifier, int $now): void {
        global $DB;
        $DB->insert_record(self::TABLE, (object) [
            'bucket' => $bucket,
            'identifier' => self::hash($identifier),
            'timecreated' => $now,
        ]);
    }

    /**
     * Count hits for an identifier within the window.
     *
     * @param string $bucket Logical action name.
     * @param string $identifier Opaque per-actor identifier.
     * @param int $window Sliding window length in seconds.
     * @param int $now Current time.
     * @return int
     */
    private static function count(string $bucket, string $identifier, int $window, int $now): int {
        global $DB;
        return $DB->count_records_select(
            self::TABLE,
            'bucket = :bucket AND identifier = :identifier AND timecreated > :cutoff',
            ['bucket' => $bucket, 'identifier' => self::hash($identifier), 'cutoff' => $now - $window]
        );
    }

    /**
     * Hash an identifier to a fixed-width, non-reversible, unguessable key.
     *
     * HMAC-SHA256 with a per-site secret defeats the offline dictionary attack that an unsalted hash
     * of a low-entropy identifier (an IPv4 address or an email) would otherwise allow.
     *
     * @param string $identifier Raw identifier.
     * @return string
     */
    private static function hash(string $identifier): string {
        return hash_hmac('sha256', $identifier, self::secret());
    }

    /**
     * The per-site secret used to key the identifier HMAC, created on first use.
     *
     * @return string
     */
    private static function secret(): string {
        $secret = get_config('auth_flexaccess', 'ratelimitsecret');
        if (empty($secret)) {
            $secret = bin2hex(random_bytes(32));
            set_config('ratelimitsecret', $secret, 'auth_flexaccess');
        }
        return (string) $secret;
    }
}
