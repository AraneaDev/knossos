<?php

declare(strict_types=1);

/*
 * The PHP floors over pcov's per-process data.
 *
 *   php tools/pcov-report.php [--root=DIR] [--budgets=FILE]
 *
 * --root is the tree whose coverage/php data and sources are read, this
 * checkout when it is not given; --budgets the floors, the root's
 * coverage-budgets.json when it is not given. The budgets are checked before
 * any floor is applied: a missing file, a floor that is not a number from 0 to
 * 100, a measured component with no floor or a floor no component answers to
 * stops the report with exit 1, where it once read the first two as 90 and 0.
 */
require __DIR__ . '/lib/coverage-budgets.php';

/**
 * Every top-level directory under src/, and the PHP component whose floor its
 * files count toward. A directory missing here stops the report: see
 * componentOf().
 */
const SOURCE_COMPONENTS = [
    'Boundary' => 'query-analysis',
    'Bundle' => 'bundle-git-watch',
    'Cancellation' => 'scanner-runtime',
    'Classification' => 'query-analysis',
    'Cli' => 'bundle-git-watch',
    'Configuration' => 'discovery-config',
    'Discovery' => 'discovery-config',
    'Enrichment' => 'bundle-git-watch',
    'Filesystem' => 'bundle-git-watch',
    'Git' => 'bundle-git-watch',
    'Maintenance' => 'maintenance-runtime',
    'Mcp' => 'transport',
    'Query' => 'query-analysis',
    'Reconciliation' => 'reconciliation',
    'Result' => 'query-analysis',
    'Roots' => 'discovery-config',
    'Runtime' => 'maintenance-runtime',
    'Scan' => 'scanner-runtime',
    'Scanner' => 'scanner-runtime',
    'Store' => 'storage',
    'Watch' => 'bundle-git-watch',
];

/**
 * Files moved out of their directory to break namespace cycles, counted where
 * they always were, so no component's floor shifts.
 */
const MOVED_FILES = [
    'src/Scan/TreeFingerprint.php' => 'bundle-git-watch',
    'src/Store/ContributionCacheEntry.php' => 'reconciliation',
    'src/Store/ScanBusyException.php' => 'scanner-runtime',
    'src/Scan/ScanLedger.php' => 'query-analysis',
    'src/Scan/ScanLedgerSpan.php' => 'query-analysis',
    'src/Scan/ScanTarget.php' => 'query-analysis',
];

/**
 * The component a measured file, relative to the root, counts toward; null for
 * a file under src/ whose top-level entry SOURCE_COMPONENTS does not name.
 */
function componentOf(string $relative): ?string
{
    if (isset(MOVED_FILES[$relative])) {
        return MOVED_FILES[$relative];
    }
    if ($relative === 'bin/http-router.php') {
        return 'transport';
    }
    if (str_starts_with($relative, 'workers/php/src/')) {
        return 'php-scanner';
    }
    $top = explode('/', substr($relative, strlen('src/')), 2)[0];

    return SOURCE_COMPONENTS[$top] ?? null;
}

$root = dirname(__DIR__);
$budgetPath = null;
require_once __DIR__ . '/lib/cli-arguments.php';
// $argv is only a global where register_argc_argv is on (see tools/lib/cli-arguments.php).
$argv = cliArguments();
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--root=') && $argument !== '--root=') {
        $root = rtrim(substr($argument, strlen('--root=')), '/');
    } elseif (str_starts_with($argument, '--budgets=') && $argument !== '--budgets=') {
        $budgetPath = substr($argument, strlen('--budgets='));
    } else {
        fwrite(STDERR, "usage: php tools/pcov-report.php [--root=DIR] [--budgets=FILE]\n");
        exit(2);
    }
}
$budgetPath ??= $root . '/coverage-budgets.json';
$coverageDirectory = $root . '/coverage/php';
$merged = [];
foreach (glob($coverageDirectory . '/pcov-*.json') ?: [] as $path) {
    $processData = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    foreach ($processData as $file => $lines) {
        if (!is_array($lines)) {
            continue;
        }
        foreach ($lines as $line => $hits) {
            $merged[$file][(int) $line] = max($merged[$file][(int) $line] ?? -1, (int) $hits);
        }
    }
}

$prefixes = [$root . '/src/', $root . '/workers/php/src/', $root . '/bin/http-router.php'];

// pcov only reports files that were actually loaded during the run. A source
// file that no test ever executes never appears in the merged data and would
// silently drop out of the aggregate -- inflating coverage by pretending the
// gap does not exist. Enumerate every file that SHOULD be measured and inject
// any that are missing as fully uncovered (every executable line at 0 hits), so
// an unexecuted file shows as 0% and drags the aggregate/component floors down
// where it belongs.
$approxExecutableLines = static function (string $path): int {
    $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
    $count = 0;
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if (
            $trimmed === '' || $trimmed === '{' || $trimmed === '}' || $trimmed === '<?php'
            || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')
            || str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '#')
            || str_starts_with($trimmed, 'declare(') || str_starts_with($trimmed, 'namespace ')
            || str_starts_with($trimmed, 'use ')
        ) {
            continue;
        }
        ++$count;
    }

    return max(1, $count);
};
// Constant-only files: the version, and the CLI entry point that re-exports it.
$excluded = [$root . '/src/Application.php', $root . '/src/Runtime/Version.php'];
$expectedFiles = is_file($root . '/bin/http-router.php') ? [$root . '/bin/http-router.php' => true] : [];
foreach ([$root . '/src', $root . '/workers/php/src'] as $sourceDirectory) {
    if (!is_dir($sourceDirectory)) {
        continue;
    }
    /** @var iterable<\SplFileInfo> $iterator */
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceDirectory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $fileInfo) {
        if ($fileInfo->isFile() && $fileInfo->getExtension() === 'php') {
            $expectedFiles[$fileInfo->getPathname()] = true;
        }
    }
}
foreach (array_keys($expectedFiles) as $expectedFile) {
    if (isset($merged[$expectedFile]) || in_array($expectedFile, $excluded, true)) {
        continue;
    }
    $merged[$expectedFile] = array_fill(1, $approxExecutableLines($expectedFile), 0);
}
$covered = 0;
$executable = 0;
$files = [];
$components = [];
/** @var array<string, true> $unmapped top-level src/ entries no component claims */
$unmapped = [];
/** @var array<string, array<int, int>> $cloverFiles per-file line hits for the Clover report */
$cloverFiles = [];
foreach ($merged as $file => $lines) {
    if (in_array($file, $excluded, true)) {
        continue;
    }
    if (count(array_filter($prefixes, static fn(string $prefix): bool => str_starts_with($file, $prefix))) === 0) {
        continue;
    }
    $fileCovered = count(array_filter($lines, static fn(int $hits): bool => $hits > 0));
    $fileExecutable = count($lines);
    $covered += $fileCovered;
    $executable += $fileExecutable;
    $files[str_replace($root . '/', '', $file)] = [$fileCovered, $fileExecutable];
    $cloverFiles[$file] = $lines;
    $relative = str_replace($root . '/', '', $file);
    $component = componentOf($relative);
    if ($component === null) {
        $unmapped[preg_replace('#^(src/[^/]+).*$#', '$1', $relative)] = true;
        continue;
    }
    $components[$component][0] = ($components[$component][0] ?? 0) + $fileCovered;
    $components[$component][1] = ($components[$component][1] ?? 0) + $fileExecutable;
}
// A new directory under src/ is counted nowhere until it is given a component:
// a default would fold it silently into whichever floor that happens to be.
if ($unmapped !== []) {
    ksort($unmapped, SORT_STRING);
    foreach (array_keys($unmapped) as $entry) {
        fwrite(STDERR, sprintf("pcov-report: %s has no coverage component; add it to SOURCE_COMPONENTS in tools/pcov-report.php\n", $entry));
    }
    exit(1);
}
ksort($files, SORT_STRING);
$percent = $executable === 0 ? 0.0 : 100 * $covered / $executable;
foreach ($files as $file => [$fileCovered, $fileExecutable]) {
    printf("%-70s %6.2f%% (%d/%d)\n", $file, 100 * $fileCovered / max(1, $fileExecutable), $fileCovered, $fileExecutable);
}
printf("PHP aggregate line coverage: %.2f%% (%d/%d)\n", $percent, $covered, $executable);

[$budgets, $budgetErrors] = coverageBudgetFile($budgetPath, array_map('strval', array_keys($components)));
if ($budgets === null || $budgetErrors !== []) {
    foreach ($budgetErrors as $error) {
        fwrite(STDERR, sprintf("coverage budgets %s: %s\n", $budgetPath, $error));
    }
    exit(1);
}
$componentSummary = [];
$componentPassed = true;
ksort($components, SORT_STRING);
foreach ($components as $component => [$componentCovered, $componentExecutable]) {
    $componentPercent = 100 * $componentCovered / max(1, $componentExecutable);
    $floor = (float) $budgets['php']['components'][$component];
    $passed = $componentPercent >= $floor;
    $componentPassed = $componentPassed && $passed;
    printf("PHP component %-24s %6.2f%% (%d/%d), floor %.2f%% %s\n", $component, $componentPercent, $componentCovered, $componentExecutable, $floor, $passed ? 'PASS' : 'FAIL');
    $componentSummary[$component] = ['covered' => $componentCovered, 'valid' => $componentExecutable, 'percent' => round($componentPercent, 2), 'floor' => $floor, 'passed' => $passed];
}
$aggregateFloor = (float) $budgets['php']['aggregate_lines'];
$aggregatePassed = $percent >= $aggregateFloor;
printf("PHP aggregate %.2f%%, floor %.2f%% %s\n", $percent, $aggregateFloor, $aggregatePassed ? 'PASS' : 'FAIL');
$summary = ['lines' => ['covered' => $covered, 'valid' => $executable, 'percent' => round($percent, 2)], 'components' => $componentSummary, 'files' => $files];
file_put_contents($coverageDirectory . '/summary.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

// Clover XML alongside summary.json, because summary.json is our own shape and no coverage
// service reads it. PHPUnit's --coverage-clover is not an option here: coverage is collected
// out of band by tools/pcov-prepend.php so it also captures the subprocess test runs, which
// PHPUnit's own driver never sees. Emitting from the merged data keeps the XML and the
// aggregate percentage in agreement by construction, since both read $merged through the
// same prefix filter.
$timestamp = (string) time();
$xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
$xml .= sprintf("<coverage generated=\"%s\">\n  <project timestamp=\"%s\">\n", $timestamp, $timestamp);
ksort($cloverFiles, SORT_STRING);
foreach ($cloverFiles as $file => $lines) {
    $fileCovered = count(array_filter($lines, static fn(int $hits): bool => $hits > 0));
    $xml .= sprintf("    <file name=\"%s\">\n", htmlspecialchars($file, ENT_XML1 | ENT_QUOTES, 'UTF-8'));
    ksort($lines, SORT_NUMERIC);
    foreach ($lines as $line => $hits) {
        $xml .= sprintf("      <line num=\"%d\" type=\"stmt\" count=\"%d\"/>\n", $line, max(0, $hits));
    }
    $xml .= sprintf(
        "      <metrics statements=\"%d\" coveredstatements=\"%d\"/>\n    </file>\n",
        count($lines),
        $fileCovered,
    );
}
$xml .= sprintf(
    "    <metrics files=\"%d\" statements=\"%d\" coveredstatements=\"%d\"/>\n  </project>\n</coverage>\n",
    count($cloverFiles),
    $executable,
    $covered,
);
file_put_contents($coverageDirectory . '/clover.xml', $xml);
exit($aggregatePassed && $componentPassed ? 0 : 1);
