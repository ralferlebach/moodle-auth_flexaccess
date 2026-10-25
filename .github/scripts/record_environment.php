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
 * CI only: print the tested combination as Markdown (tool_flexaccess #12).
 *
 * Moodle release, PHP and database server versions of the CI site, the commit and version of each
 * FlexAccess plugin from the family lock (environment variable FAMILY_LOCK), the version found in the Moodle
 * tree and - where moodle-plugin-ci initialised a test site (PHPUnit or Behat) - the version Moodle actually
 * installed there, for each of them and for tool_mergeusers. Exits with 1 when a plugin is missing or a
 * version differs from the lock, so a job can never test another combination than the pinned one.
 *
 * moodle-plugin-ci installs no regular site, only the test sites (own table prefixes). This script
 * therefore stops Moodle's bootstrap after config.php (no database access through Moodle) and reads the
 * test sites' tables directly.
 *
 * Usage: php record_environment.php <moodledir> [--with-mergeusers]
 *   --with-mergeusers: the job installed tool_mergeusers (fetch --with-mergeusers); it must then match the lock.
 *   Without it, tool_mergeusers must be absent, so no job tests with an unpinned copy.
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
define('ABORT_AFTER_CONFIG', true);
require(rtrim($argv[1] ?? 'moodle', '/') . '/config.php');

$withmergeusers = in_array('--with-mergeusers', array_slice($argv, 2), true);
$lock = json_decode((string) getenv('FAMILY_LOCK'), true) ?: ['plugins' => [], 'mergeusers' => []];
$paths = [
    'auth_flexaccess' => 'auth/flexaccess',
    'enrol_flexaccess' => 'enrol/flexaccess',
    'mod_flexaccess' => 'mod/flexaccess',
    'tool_flexaccess' => 'admin/tool/flexaccess',
    'tool_mergeusers' => 'admin/tool/mergeusers',
];

/**
 * The version a version.php declares, or '' when the file is missing.
 *
 * @param string $file version.php path.
 * @return string
 */
function flexaccess_ci_file_version(string $file): string {
    if (!is_readable($file)) {
        return '';
    }
    return preg_match('/\$plugin->version\s*=\s*([0-9]{10})/', (string) file_get_contents($file), $m) ? $m[1] : '';
}

// Moodle release from the core version.php ($CFG->dirroot is public/ on Moodle 5.1+).
$release = '?';
if (preg_match('/\$release\s*=\s*\'([^\']+)\'/', (string) @file_get_contents($CFG->dirroot . '/version.php'), $m)) {
    $release = $m[1];
}

// The test sites moodle-plugin-ci initialised, and the database server version.
$prefixes = array_filter([
    'PHPUnit' => $CFG->phpunit_prefix ?? '',
    'Behat' => $CFG->behat_prefix ?? '',
]);
$server = '?';
$testsites = [];
$port = (int) ($CFG->dboptions['dbport'] ?? 0);
// The native drivers Moodle itself needs (pgsql, mysqli); PDO drivers are not guaranteed on CI runners.
if ($CFG->dbtype === 'pgsql') {
    $conn = @pg_connect(sprintf(
        "host='%s' port='%d' dbname='%s' user='%s' password='%s'",
        $CFG->dbhost,
        $port ?: 5432,
        $CFG->dbname,
        $CFG->dbuser,
        $CFG->dbpass
    ));
    if ($conn) {
        $server = (string) (pg_version($conn)['server'] ?? '?');
        foreach ($prefixes as $label => $prefix) {
            $result = @pg_query($conn, "SELECT plugin, value FROM {$prefix}config_plugins WHERE name = 'version'");
            if ($result) {
                $testsites[$label] = array_column(pg_fetch_all($result) ?: [], 'value', 'plugin');
            }
        }
    }
} else {
    mysqli_report(MYSQLI_REPORT_OFF);
    $conn = @new mysqli($CFG->dbhost, $CFG->dbuser, $CFG->dbpass, $CFG->dbname, $port ?: 3306);
    if (!$conn->connect_errno) {
        $server = (string) $conn->server_info;
        foreach ($prefixes as $label => $prefix) {
            $result = $conn->query("SELECT plugin, value FROM {$prefix}config_plugins WHERE name = 'version'");
            if ($result) {
                $testsites[$label] = array_column($result->fetch_all(MYSQLI_ASSOC), 'value', 'plugin');
            }
        }
    }
}

echo "\n### Tested combination\n\n";
echo '- Moodle ' . $release . "\n";
echo '- PHP ' . PHP_VERSION . "\n";
echo '- Database ' . $CFG->dbtype . ' ' . $server . "\n";
echo '- Test sites: ' . ($testsites ? implode(', ', array_keys($testsites)) : 'none initialised (static checks only)') . "\n\n";
$columns = array_map(static fn(string $label): string => 'Installed (' . $label . ')', array_keys($testsites));
echo '| Component | Commit | Lock version | In the Moodle tree | ' . implode(' | ', $columns) . " |\n";
echo '|---|---|---|---|' . str_repeat('---|', count($columns)) . "\n";

$problems = [];
foreach ($paths as $component => $path) {
    $entry = $component === 'tool_mergeusers' ? ($lock['mergeusers'] ?? []) : ($lock['plugins'][$component] ?? []);
    $expected = (string) ($entry['version'] ?? '');
    $infiles = flexaccess_ci_file_version($CFG->dirroot . '/' . $path . '/version.php');
    if ($component === 'tool_mergeusers' && !$withmergeusers) {
        // Not part of this job: it must not be there (an unpinned copy would be tested otherwise).
        echo '| ' . $component . ' | - | - | ' . ($infiles !== '' ? $infiles : 'not installed (not needed)')
            . str_repeat(' | -', count($testsites)) . " |\n";
        if ($infiles !== '') {
            $problems[] = $component . ' is present although this job does not pin it';
        }
        continue;
    }
    $row = [$component, $entry['sha'] ?? '?', $expected !== '' ? $expected : '?', $infiles !== '' ? $infiles : 'missing'];
    if ($expected === '' || $infiles !== $expected) {
        $problems[] = $component . ' in the Moodle tree';
    }
    foreach ($testsites as $label => $versions) {
        $installed = (string) ($versions[$component] ?? '');
        $row[] = $installed !== '' ? $installed : 'not installed';
        if ($installed !== $expected) {
            $problems[] = $component . ' on the ' . $label . ' site';
        }
    }
    echo '| ' . implode(' | ', $row) . " |\n";
}
if ($problems) {
    echo "\nThe tested combination differs from the family lock: " . implode(', ', $problems) . "\n";
    exit(1);
}
echo "\nThe tested combination matches the family lock.\n";
