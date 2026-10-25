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
 * Classify every table the installed tool_mergeusers processes by how FlexAccess treats it (docs/merge_policy.md, §9).
 *
 * moved: rows move, no collision key (nothing is lost); review / benign: collisions are archived before they
 * are resolved (review keeps the merge under review); excluded: not merged; dedicated: handled by the merger
 * of another table; gap: a merger FlexAccess cannot wrap (merges involving FlexAccess are refused).
 * Read-only. Uses the configuration tool_mergeusers itself loads, including FlexAccess' hooks.
 * Usage: php auth/flexaccess/cli/mergeusers_coverage.php [--format=json|markdown]
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

if (!class_exists('\tool_mergeusers\local\user_merger')) {
    cli_error('tool_mergeusers is not installed.');
}

[$options] = cli_get_params(['format' => 'json'], []);
$merger = new \tool_mergeusers\local\user_merger();
$read = function (string $property) use ($merger) {
    $reflection = new \ReflectionProperty($merger, $property);
    $reflection->setAccessible(true);
    return $reflection->getValue($merger);
};
$fields = $read('userfieldspertable');
$skip = array_keys($read('tablestoskip'));
$byother = array_keys($read('tablesprocessedbytablemergers'));
$keys = \auth_flexaccess\local\merge_coverage::keys();
$mergers = (array) \tool_mergeusers\local\config::instance()->tablemergers;
$gaps = \auth_flexaccess\local\merge_coverage::gaps();

// Every table tool_mergeusers touches or deliberately leaves out, classified by how FlexAccess treats it.
$classes = ['moved' => [], 'review' => [], 'benign' => [], 'excluded' => [], 'dedicated' => [], 'gap' => []];
foreach (array_unique(array_merge(array_keys($fields), $skip, $byother)) as $table) {
    if (!$DB->get_manager()->table_exists($table)) {
        continue;
    }
    if (isset($gaps[$table])) {
        $classes['gap'][$table] = $gaps[$table];
    } else if (in_array($table, $skip, true)) {
        $classes['excluded'][$table] = 'not merged';
    } else if (in_array($table, $byother, true)) {
        $classes['dedicated'][$table] = 'handled by the merger of another table';
    } else if (isset($keys[$table])) {
        $kind = \auth_flexaccess\local\merge_coverage::kind($table);
        $classes[$kind][$table] = implode(',', $keys[$table][1]);
    } else {
        $classes['moved'][$table] = implode(',', $fields[$table] ?? []);
    }
}
foreach ($classes as &$list) {
    ksort($list);
}
unset($list);
$facts = [
    'moodle' => $CFG->release,
    'mergeusers' => get_config('tool_mergeusers', 'version'),
    'wrappedmergers' => array_map(static fn($c) => ltrim(is_object($c) ? get_class($c) : (string) $c, '\\'), $mergers),
    'counts' => array_map('count', $classes),
    'tables' => $classes,
];
if ($options['format'] === 'markdown') {
    echo "Moodle {$facts['moodle']}, tool_mergeusers {$facts['mergeusers']}: "
        . implode(', ', array_map(static fn($k, $v) => "$k $v", array_keys($facts['counts']), $facts['counts'])) . "\n";
    foreach (['review', 'benign', 'excluded', 'dedicated', 'gap'] as $class) {
        $items = array_map(static fn($t, $k) => "`$t` ($k)", array_keys($classes[$class]), $classes[$class]);
        echo "- $class: " . ($items ? implode(', ', $items) : '-') . "\n";
    }
    exit(0);
}
echo json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
