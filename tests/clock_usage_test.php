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
 * Static gate: no direct wall-clock reads anywhere in the plugin - code, CLI, tests, fixtures (Clock API).
 *
 * @package    auth_flexaccess
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace auth_flexaccess;

/**
 * Every current point in time is read through `\core\di::get(\core\clock::class)` (frozen or incrementing
 * clocks in tests, an explicit `$now` where one is passed). The gate scans every PHP file of the plugin with
 * the PHP tokenizer (comments and strings are no calls) and every JavaScript file for the calls that read the
 * wall clock.
 *
 * Documented exceptions ({@see self::EXCEPTIONS}): monotonic duration measurement (a frozen calendar clock
 * must not stop a time budget) and the alignment with Moodle core, which reads `time()` itself for enrolment
 * windows. An exception needs both an entry here and the marker `clock-exception` on the line. Formatting of a
 * given timestamp (`userdate()`, `date($format, $timestamp)`) reads no clock and is not flagged.
 *
 * @package    auth_flexaccess
 * @coversNothing
 */
final class clock_usage_test extends \advanced_testcase {
    /** Calls that always read the wall clock. */
    private const ALWAYS = [
        'time', 'microtime', 'hrtime', 'gettimeofday', 'localtime', 'date_create', 'date_create_immutable',
    ];

    /** Calls that read the wall clock when called with fewer arguments than given here. */
    private const WITHOUT_TIMESTAMP = ['date' => 2, 'gmdate' => 2, 'idate' => 2, 'getdate' => 1, 'strtotime' => 2,
        'mktime' => 6, 'gmmktime' => 6, 'usergetdate' => 1];

    /** Documented exceptions: path relative to the plugin => [function => reason]. */
    private const EXCEPTIONS = [];

    /**
     * Wall-clock reads in PHP source, as [line, function] pairs.
     *
     * @param string $source PHP source.
     * @return array<int, array{0: int, 1: string}>
     */
    public static function scan_php(string $source): array {
        $tokens = array_values(array_filter(
            token_get_all($source),
            static fn($t) => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
        $found = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token)) {
                continue;
            }
            $name = strtolower(ltrim($token[1], '\\'));
            $next = $tokens[$i + 1] ?? null;
            $prev = $tokens[$i - 1] ?? null;
            if ($token[0] === T_NEW) {
                $class = $tokens[$i + 1] ?? null;
                $open = $tokens[$i + 2] ?? null;
                if (is_array($class) && in_array(strtolower(ltrim($class[1], '\\')), ['datetime', 'datetimeimmutable'], true)) {
                    $arg = $tokens[$i + 3] ?? null;
                    $now = is_array($arg) && $arg[0] === T_CONSTANT_ENCAPSED_STRING && strtolower(trim($arg[1], '\'"')) === 'now';
                    if ($open !== '(' || $arg === ')' || $now) {
                        $found[] = [$token[2], 'new ' . $class[1]];
                    }
                }
                continue;
            }
            if (!in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true) || $next !== '(') {
                continue;
            }
            $member = [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_NULLSAFE_OBJECT_OPERATOR];
            if (is_array($prev) && in_array($prev[0], $member, true)) {
                continue;
            }
            if (in_array($name, self::ALWAYS, true)) {
                $found[] = [$token[2], $name];
            } else if (isset(self::WITHOUT_TIMESTAMP[$name]) && self::arguments($tokens, $i + 1) < self::WITHOUT_TIMESTAMP[$name]) {
                $found[] = [$token[2], $name];
            }
        }
        return $found;
    }

    /**
     * Number of top-level arguments of the call whose opening parenthesis is at $open.
     *
     * @param array $tokens Tokens without whitespace and comments.
     * @param int $open Index of "(".
     * @return int
     */
    private static function arguments(array $tokens, int $open): int {
        $depth = 0;
        $args = 0;
        $empty = true;
        for ($i = $open; $i < count($tokens); $i++) {
            $t = $tokens[$i];
            $text = is_array($t) ? $t[1] : $t;
            if (in_array($text, ['(', '[', '{'], true) || (is_array($t) && $t[0] === T_CURLY_OPEN)) {
                $depth++;
                continue;
            }
            if (in_array($text, [')', ']', '}'], true)) {
                $depth--;
                if ($depth === 0) {
                    return $empty ? 0 : $args + 1;
                }
                continue;
            }
            if ($depth === 1) {
                $empty = false;
                if ($text === ',') {
                    $args++;
                }
            }
        }
        return $args;
    }

    /**
     * Wall-clock reads in JavaScript source, as [line, call] pairs (Date.now(), new Date() without argument).
     *
     * @param string $source JavaScript source.
     * @return array<int, array{0: int, 1: string}>
     */
    public static function scan_js(string $source): array {
        $found = [];
        // Block and line comments are no calls.
        $code = preg_replace_callback('~/\*.*?\*/|//[^\n]*~s', static fn($m) => preg_replace('/[^\n]/', ' ', $m[0]), $source);
        foreach (explode("\n", $code) as $index => $line) {
            if (preg_match_all('/\bDate\.now\s*\(|\bnew\s+Date\s*\(\s*\)/', $line, $matches)) {
                foreach ($matches[0] as $match) {
                    $found[] = [$index + 1, trim($match)];
                }
            }
        }
        return $found;
    }

    /**
     * Source files of the plugin.
     *
     * @return string[] Absolute paths.
     */
    private static function files(): array {
        $root = dirname(__DIR__);
        $out = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            static fn(\SplFileInfo $f) => !in_array($f->getFilename(), ['.git', 'node_modules', 'vendor', 'build'], true)
        ));
        foreach ($iterator as $file) {
            if (in_array($file->getExtension(), ['php', 'js'], true)) {
                $out[] = $file->getPathname();
            }
        }
        sort($out);
        return $out;
    }

    /**
     * The gate: no direct wall-clock read outside the documented exceptions.
     */
    public function test_no_direct_wall_clock_reads(): void {
        $root = dirname(__DIR__) . '/';
        $violations = [];
        $used = [];
        foreach (self::files() as $path) {
            $relative = substr($path, strlen($root));
            $source = file_get_contents($path);
            $lines = explode("\n", $source);
            $found = str_ends_with($path, '.php') ? self::scan_php($source) : self::scan_js($source);
            foreach ($found as [$line, $call]) {
                $allowed = isset(self::EXCEPTIONS[$relative][$call])
                    && str_contains($lines[$line - 1] ?? '', 'clock-exception');
                if ($allowed) {
                    $used[$relative][$call] = true;
                } else {
                    $violations[] = "$relative:$line $call()";
                }
            }
        }
        $this->assertSame([], $violations, 'Read the time through \core\di::get(\core\clock::class).');
        $documented = array_map(static fn(array $calls): array => array_fill_keys(array_keys($calls), true), self::EXCEPTIONS);
        $this->assertEquals($documented, $used, 'Every documented exception is still used (otherwise remove it).');
    }

    /**
     * The gate finds what it is meant to find, and nothing else.
     */
    public function test_scanner_finds_wall_clock_reads(): void {
        $php = "<?php\n\$a = time();\n\$b = \\microtime(true);\n\$c = date('Y');\n\$d = date('Y', \$t);\n"
            . "\$e = new \\DateTime();\n\$f = new DateTimeImmutable('@1');\n\$g = \$clock->time();\n"
            . "// time();\n\$h = 'time()';\n\$i = mktime(0, 0, 0, 1, 1, 2026);\n\$j = strtotime('tomorrow');\n"
            . "\$k = \\core\\di::get(\\core\\clock::class)->time();\nfunction time_left() {}\n";
        $expected = [[2, 'time'], [3, 'microtime'], [4, 'date'], [6, 'new \\DateTime'], [12, 'strtotime']];
        $this->assertSame($expected, self::scan_php($php));
        $js = "const a = Date.now();\n// Date.now();\nconst b = new Date();\nconst c = new Date(ts * 1000);\n";
        $this->assertSame([[1, 'Date.now('], [3, 'new Date()']], self::scan_js($js));
    }
}
