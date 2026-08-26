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

/**
 * Evaluate a Clover coverage report for this plugin only and enforce a floor.
 *
 * PHPUnit's text summary covers the whole Moodle tree, so its percentage says nothing about this
 * plugin (it reported ~2% simply because core dominates the line count). The Clover XML carries
 * per-file counters, so the figure can be restricted to the plugin's own shipped code.
 *
 * Usage: php tools/coverage_gate.php <clover.xml> <floor-percent>
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Standalone CI helper, not a file included by Moodle, so a MOODLE_INTERNAL check would be wrong.
// phpcs:disable moodle.Files.MoodleInternal.MoodleInternalGlobalState
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('This developer tool can only be run from the command line.');
}

if ($argc < 3) {
    fwrite(STDERR, "Usage: coverage_gate.php <clover.xml> <floor-percent>\n");
    exit(1);
}

$reportfile = $argv[1];
$floor = (float) $argv[2];

if (!is_readable($reportfile)) {
    fwrite(STDERR, "Clover-Report nicht lesbar: $reportfile\n");
    exit(1);
}

$xml = simplexml_load_file($reportfile);
if ($xml === false) {
    fwrite(STDERR, "Clover-Report konnte nicht geparst werden.\n");
    exit(1);
}

$covered = 0;
$total = 0;
foreach ($xml->xpath('//file') as $file) {
    $name = (string) $file['name'];
    // Only this plugin, and only the code that ships: the tests themselves are not the subject.
    if (strpos($name, '/flexaccess/') === false || strpos($name, '/tests/') !== false) {
        continue;
    }
    $metrics = $file->metrics;
    $total += (int) $metrics['statements'];
    $covered += (int) $metrics['coveredstatements'];
}

if ($total === 0) {
    fwrite(STDERR, "Keine Plugin-Dateien im Report gefunden - das Gate ist nicht bewertbar.\n");
    exit(1);
}

$pct = round($covered / $total * 100, 2);
printf("Line coverage (nur dieses Plugin): %s%% (%d/%d), Mindestwert %s%%\n", $pct, $covered, $total, $floor);
exit($pct < $floor ? 1 : 0);
