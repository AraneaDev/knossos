<?php

declare(strict_types=1);

/**
 * List, check or tag the PHPUnit tests that take more than five seconds.
 *
 *   php tools/tag-slow-tests.php [--cache=FILE] [--root=DIR] [--threshold=SECONDS] [--check | --write]
 *
 * It reads the times PHPUnit keeps in its result cache
 * (`.phpunit.cache/test-results`, written by any run without
 * `--do-not-cache-result`) and takes each test method at the time of its
 * slowest data set. A method over the threshold is slow, and is tagged when
 * it or its class carries `#[Group('slow')]`. Without a mode the tool lists
 * the slow methods; `--check` exits 1 when one is untagged; `--write` adds
 * the group above each untagged method, and the import where it is missing.
 * Test files are found through the PSR-4 `autoload-dev` map of the root's
 * composer.json. A cached test that is no longer in the tree is reported on
 * STDERR and skipped, since the cache keeps the times of deleted tests.
 *
 * The times are the machine's that ran the suite, so this is a local tool:
 * run the suite, then `--write`, and review the diff.
 */

require_once __DIR__ . '/lib/cli-arguments.php';

const SLOW_GROUP_ATTRIBUTE = "#[Group('slow')]";
const SLOW_GROUP_IMPORT = 'use PHPUnit\\Framework\\Attributes\\Group;';

/**
 * The slowest time of each test method over `$threshold` seconds, slowest
 * first. A data-provided test is cached once per data set; all of them share
 * the method, and the method's attribute.
 *
 * @param array<mixed> $times the `times` map of the result cache
 * @return array<string, float> seconds by `Class::method`
 */
function slowTestMethods(array $times, float $threshold): array
{
    $slow = [];
    foreach ($times as $test => $seconds) {
        $method = explode(' with data set ', (string) $test, 2)[0];
        if (is_numeric($seconds) && (float) $seconds > $threshold && str_contains($method, '::')) {
            $slow[$method] = max($slow[$method] ?? 0.0, (float) $seconds);
        }
    }
    arsort($slow);

    return $slow;
}

/**
 * The PSR-4 `autoload-dev` prefixes of the root's composer.json, longest first.
 *
 * @return array<string, string> directory relative to the root, by namespace prefix
 */
function testNamespacePrefixes(string $root): array
{
    $composer = json_decode((string) @file_get_contents($root . '/composer.json'), true);
    $prefixes = [];
    $map = is_array($composer) ? ($composer['autoload-dev']['psr-4'] ?? []) : [];
    foreach (is_array($map) ? $map : [] as $prefix => $directory) {
        if (is_string($directory)) {
            $prefixes[(string) $prefix] = rtrim($directory, '/');
        }
    }
    uksort($prefixes, static fn(string $left, string $right): int => strlen($right) <=> strlen($left));

    return $prefixes;
}

/**
 * The file that declares `$class`, or null when no prefix maps it to a file.
 *
 * @param array<string, string> $prefixes
 */
function testClassFile(string $class, array $prefixes, string $root): ?string
{
    foreach ($prefixes as $prefix => $directory) {
        if (str_starts_with($class, $prefix)) {
            $path = $root . '/' . $directory . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

            return is_file($path) ? $path : null;
        }
    }

    return null;
}

/**
 * The index of the first line `$pattern` matches, or null.
 *
 * @param list<string> $lines
 */
function lineMatching(array $lines, string $pattern): ?int
{
    foreach ($lines as $index => $line) {
        if (preg_match($pattern, $line) === 1) {
            return $index;
        }
    }

    return null;
}

/** The pattern of the line that declares method `$method`. */
function methodPattern(string $method): string
{
    return '/^\s*(?:(?:public|protected|private|static|final|abstract)\s+)*function\s+' . preg_quote($method, '/') . '\s*\(/';
}

/** The pattern of the line that declares class `$class`, by its short name. */
function classPattern(string $class): string
{
    $short = substr((string) strrchr('\\' . $class, '\\'), 1);

    return '/^\s*(?:(?:final|abstract|readonly)\s+)*class\s+' . preg_quote($short, '/') . '\b/';
}

/**
 * Whether the attributes and doc comment directly above line `$index` put it
 * in the slow group.
 *
 * @param list<string> $lines
 */
function declarationIsSlow(array $lines, int $index): bool
{
    for ($above = $index - 1; $above >= 0; --$above) {
        $line = trim($lines[$above]);
        $comment = str_starts_with($line, '*') || str_starts_with($line, '/**') || str_starts_with($line, '#[');
        if ($line === '' || (!$comment && (str_ends_with($line, '{') || str_ends_with($line, '}') || str_ends_with($line, ';')))) {
            return false;
        }
        if (preg_match('/Group\(\s*[\'"]slow[\'"]\s*\)/', $line) === 1) {
            return true;
        }
    }

    return false;
}

/**
 * `$source` with the `Group` attribute imported, among the class imports in
 * their sorted place, or after the namespace when the file has none.
 */
function withGroupImport(string $source): string
{
    $lines = explode("\n", $source);
    if (in_array(SLOW_GROUP_IMPORT, $lines, true)) {
        return $source;
    }
    $imports = array_keys(array_filter($lines, static fn(string $line): bool => preg_match('/^use [A-Z][\w\\\\]*;$/', $line) === 1));
    if ($imports === []) {
        $namespace = lineMatching($lines, '/^namespace\s/') ?? 0;
        array_splice($lines, $namespace + 1, 0, ['', SLOW_GROUP_IMPORT]);

        return implode("\n", $lines);
    }
    $at = end($imports) + 1;
    foreach ($imports as $index) {
        if (strcasecmp($lines[$index], SLOW_GROUP_IMPORT) > 0) {
            $at = $index;
            break;
        }
    }
    array_splice($lines, $at, 0, [SLOW_GROUP_IMPORT]);

    return implode("\n", $lines);
}

/**
 * `$source` with `#[Group('slow')]` directly above each of `$methods`, at the
 * method's indent, after its doc comment and other attributes.
 *
 * @param list<string> $methods
 */
function withSlowTags(string $source, array $methods): string
{
    $lines = explode("\n", $source);
    $at = [];
    foreach ($methods as $method) {
        $index = lineMatching($lines, methodPattern($method));
        if ($index !== null && !declarationIsSlow($lines, $index)) {
            $at[$index] = (string) preg_replace('/\S.*$/', '', $lines[$index]) . SLOW_GROUP_ATTRIBUTE;
        }
    }
    if ($at === []) {
        return $source;
    }
    krsort($at);
    foreach ($at as $index => $attribute) {
        array_splice($lines, $index, 0, [$attribute]);
    }

    return withGroupImport(implode("\n", $lines));
}

$options = cliOptions(
    'usage: php tools/tag-slow-tests.php [--cache=FILE] [--root=DIR] [--threshold=SECONDS] [--check | --write]',
    ['cache', 'root', 'threshold'],
    ['check', 'write'],
);
$root = rtrim($options['root'] ?? dirname(__DIR__), '/');
$cachePath = $options['cache'] ?? $root . '/.phpunit.cache/test-results';
$threshold = is_numeric($options['threshold'] ?? '5') ? (float) ($options['threshold'] ?? '5') : 5.0;
$cache = is_file($cachePath) ? json_decode((string) file_get_contents($cachePath), true) : null;
if (!is_array($cache) || !is_array($cache['times'] ?? null)) {
    fwrite(STDERR, "tag-slow-tests: no PHPUnit result cache at {$cachePath}; run the suite first.\n");
    exit(1);
}

$prefixes = testNamespacePrefixes($root);
$untagged = [];
$byFile = [];
foreach (slowTestMethods($cache['times'], $threshold) as $test => $seconds) {
    [$class, $method] = explode('::', $test, 2);
    $file = testClassFile($class, $prefixes, $root);
    $lines = $file === null ? [] : explode("\n", (string) file_get_contents($file));
    $methodLine = lineMatching($lines, methodPattern($method));
    $classLine = lineMatching($lines, classPattern($class));
    if ($file === null || $methodLine === null) {
        fwrite(STDERR, "{$test} is in the cache but not in the tree; skipped.\n");
        continue;
    }
    $tagged = declarationIsSlow($lines, $methodLine) || ($classLine !== null && declarationIsSlow($lines, $classLine));
    printf("%8.2f s  %s  %s\n", $seconds, $test, $tagged ? 'tagged' : 'untagged');
    if (!$tagged) {
        $untagged[$test] = $seconds;
        $byFile[$file][] = $method;
    }
}

if (isset($options['write'])) {
    foreach ($byFile as $file => $methods) {
        file_put_contents($file, withSlowTags((string) file_get_contents($file), $methods));
    }
    printf("tag-slow-tests: tagged %d methods in %d files.\n", count($untagged), count($byFile));
    exit(0);
}
if (isset($options['check']) && $untagged !== []) {
    foreach ($untagged as $test => $seconds) {
        fprintf(STDERR, "%s took %.2f s and has no %s.\n", $test, $seconds, SLOW_GROUP_ATTRIBUTE);
    }
    fwrite(STDERR, "tag-slow-tests: run `php tools/tag-slow-tests.php --write`.\n");
    exit(1);
}
