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
 * Generic sliding-window action rate limiter for anonymous, resource-creating endpoints.
 *
 * Counts every action (not only failures) per opaque identifier within a bucket, and reports when a
 * limit is reached. Backed by the application cache so it holds across session-less anonymous
 * requests. Callers choose NAT-friendly limits so a shared classroom address is not blocked.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class rate_limiter {
    /**
     * Whether the identifier has reached the limit within the window.
     *
     * @param string $bucket Logical action name (e.g. 'quickreg', 'magic_ip').
     * @param string $identifier Opaque per-actor identifier (e.g. client address or email).
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
        $entry = self::cache()->get(self::key($bucket, $identifier));
        if (!is_array($entry) || $now - (int) $entry['since'] > $window) {
            return false;
        }
        return (int) $entry['count'] >= $max;
    }

    /**
     * Record one action for the identifier.
     *
     * @param string $bucket Logical action name.
     * @param string $identifier Opaque per-actor identifier.
     * @param int $window Sliding window length in seconds.
     * @param int|null $now Current time.
     * @return void
     */
    public static function record(string $bucket, string $identifier, int $window, ?int $now = null): void {
        $now = $now ?? time();
        $cache = self::cache();
        $key = self::key($bucket, $identifier);
        $entry = $cache->get($key);
        if (!is_array($entry) || $now - (int) $entry['since'] > $window) {
            $entry = ['count' => 0, 'since' => $now];
        }
        $entry['count'] = (int) $entry['count'] + 1;
        $cache->set($key, $entry);
    }

    /**
     * Clear the counter for an identifier.
     *
     * @param string $bucket Logical action name.
     * @param string $identifier Opaque per-actor identifier.
     * @return void
     */
    public static function reset(string $bucket, string $identifier): void {
        self::cache()->delete(self::key($bucket, $identifier));
    }

    /**
     * Build the opaque cache key for a bucket and identifier.
     *
     * @param string $bucket Logical action name.
     * @param string $identifier Opaque per-actor identifier.
     * @return string
     */
    private static function key(string $bucket, string $identifier): string {
        return sha1($bucket . '|' . $identifier);
    }

    /**
     * The ad-hoc application cache used to hold counters.
     *
     * @return \cache
     */
    private static function cache(): \cache {
        return \cache::make_from_params(
            \cache_store::MODE_APPLICATION,
            'auth_flexaccess',
            'ratelimit'
        );
    }
}
