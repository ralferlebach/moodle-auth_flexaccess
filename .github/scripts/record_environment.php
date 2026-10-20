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
 * Moodle release, PHP and database server versions of the installed CI site, the commit and version of
 * each FlexAccess plugin from the family lock (environment variable FAMILY_LOCK) and the version Moodle
 * actually installed for each of them and for tool_mergeusers. Exits with 1 when an installed version differs
 * from the lock, so a job can never test another combination than the pinned one.
 * Usage: php record_environment.php <moodledir>
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
require(rtrim($argv[1] ?? 'moodle', '/') . '/config.php');

$server = $DB->get_server_info();
$lock = json_decode((string) getenv('FAMILY_LOCK'), true) ?: ['plugins' => [], 'mergeusers' => []];
$installed = static function (string $component): string {
    $version = get_config($component, 'version');
    return $version === false ? 'not installed' : (string) $version;
};

echo "\n### Tested combination\n\n";
echo '- Moodle ' . $CFG->release . "\n";
echo '- PHP ' . PHP_VERSION . "\n";
echo '- Database ' . $CFG->dbtype . ' ' . ($server['version'] ?? '?') . "\n\n";
echo "| Component | Commit | Lock version | Installed version |\n|---|---|---|---|\n";
$mismatch = [];
foreach (['auth_flexaccess', 'enrol_flexaccess', 'mod_flexaccess', 'tool_flexaccess'] as $component) {
    $entry = $lock['plugins'][$component] ?? [];
    if ((string) ($entry['version'] ?? '') !== $installed($component)) {
        $mismatch[] = $component;
    }
    echo '| ' . $component . ' | ' . ($entry['sha'] ?? '?') . ' | ' . ($entry['version'] ?? '?') . ' | '
        . $installed($component) . " |\n";
}
$merge = $lock['mergeusers'] ?? [];
echo '| tool_mergeusers | ' . ($merge['sha'] ?? '?') . ' | ' . ($merge['version'] ?? '?') . ' | '
    . $installed('tool_mergeusers') . " |\n";
if ($mismatch) {
    echo "\nInstalled versions differ from the family lock: " . implode(', ', $mismatch) . "\n";
    exit(1);
}
