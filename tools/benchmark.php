<?php

declare(strict_types=1);

/*
 *   tools/benchmark [--budgets=FILE] [--report=FILE]
 *   tools/benchmark [BUDGETS [REPORT]]
 *
 * Runs each size of the budget file, then each of its scenarios.
 *
 * Exit 0 passes, 1 means a limit was exceeded or nothing was measured, 2 or
 * higher that the budget file is invalid or a run failed.
 */
require_once __DIR__ . '/lib/benchmark-budgets.php';

const BENCHMARK_ROOT = __DIR__ . '/..';

/**
 * Run a command under a wall-clock and peak-RSS measurement, killing it past the timeout.
 *
 * @return array{exit_code: int, stdout: string, stderr: string, seconds: float, peak_rss_bytes: int}
 */
function runMeasured(array $command, float $timeoutSeconds): array
{
    $pipes = [];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, BENCHMARK_ROOT);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start benchmark command.');
    }
    foreach ($pipes as $pipe) {
        stream_set_blocking($pipe, false);
    }

    $started = hrtime(true);
    $stdout = '';
    $stderr = '';
    $peakRss = 0;
    $exitCode = -1;
    while (true) {
        $status = proc_get_status($process);
        $peakRss = max($peakRss, processTreeRss((int) $status['pid']));
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        $elapsed = (hrtime(true) - $started) / 1_000_000_000;
        if (!$status['running']) {
            $exitCode = (int) $status['exitcode'];
            break;
        }
        if ($elapsed > $timeoutSeconds) {
            proc_terminate($process, 15);
            usleep(100_000);
            proc_terminate($process, 9);
            throw new RuntimeException(sprintf('Benchmark command exceeded %.1f seconds.', $timeoutSeconds));
        }
        usleep(10_000);
    }
    $stdout .= stream_get_contents($pipes[1]);
    $stderr .= stream_get_contents($pipes[2]);
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    proc_close($process);

    return [
        'exit_code' => $exitCode,
        'stdout' => $stdout,
        'stderr' => $stderr,
        'seconds' => round((hrtime(true) - $started) / 1_000_000_000, 4),
        'peak_rss_bytes' => $peakRss,
    ];
}

/** Peak resident memory across a process and its children, since scanners fan out into workers. */
function processTreeRss(int $pid): int
{
    if ($pid <= 0) {
        return 0;
    }
    $rss = 0;
    $status = @file_get_contents(sprintf('/proc/%d/status', $pid));
    if (is_string($status) && preg_match('/^VmRSS:\s+(\d+)\s+kB$/m', $status, $match)) {
        $rss += (int) $match[1] * 1024;
    }
    $children = @file_get_contents(sprintf('/proc/%d/task/%d/children', $pid, $pid));
    if (is_string($children)) {
        foreach (preg_split('/\s+/', trim($children)) ?: [] as $child) {
            if ($child !== '') {
                $rss += processTreeRss((int) $child);
            }
        }
    }

    return $rss;
}

/**
 * Shape one run's measurements for the machine-readable report.
 *
 * @param array<string, mixed> $run @return array<string, mixed>
 */
function jsonObject(array $run): array
{
    if ($run['exit_code'] !== 0) {
        throw new RuntimeException(trim($run['stderr']) ?: 'Benchmark command failed.');
    }
    $decoded = json_decode($run['stdout'], true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('Benchmark command returned invalid JSON.');
    }

    return $decoded;
}

/** Delete a directory tree, used to reset a corpus between runs. */
function removeTree(string $path): void
{
    if (!str_starts_with($path, sys_get_temp_dir() . '/knossos-benchmark-') || !is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($path);
}

/** How many edit-and-rescan rounds a scenario's median is taken over. */
const SCENARIO_ROUNDS = 5;

/**
 * Run a test-fidelity scenario under `$directory`: a cold scan of its corpus,
 * then rounds of editing the one production file and scanning again. Each
 * round judges every one of the twenty doubles again; the TypeScript, Python
 * and Rust corpora read their five test files again, the PHP one reuses them.
 * A round that parsed any other number of files than the corpus says fails
 * the run; the median round is the measurement.
 *
 * @return array<string, mixed>
 */
function runTestFidelityScenario(string $scenario, string $directory, float $limit): array
{
    $corpus = $directory . '/corpus';
    $database = $directory . '/knossos.sqlite';
    $generation = jsonObject(runMeasured([PHP_BINARY, __DIR__ . '/generate-test-fidelity-corpus.php', $corpus, BENCHMARK_SCENARIOS[$scenario]], 30));
    $scan = static fn(string $mode, float $timeout): array => runMeasured([
        PHP_BINARY, BENCHMARK_ROOT . '/bin/knossos', 'scan', $corpus,
        '--mode=' . $mode, '--snapshot-retention=2', '--db=' . $database, '--json',
    ], $timeout);
    $cold = $scan('full', $limit * 3 + 30);
    jsonObject($cold);
    $rounds = [];
    $stages = [];
    // The files the edit reaches: the production file, and for TypeScript, Python and Rust every test file of its doubles.
    $reached = (int) $generation['parsed_files'];
    for ($round = 0; $round < SCENARIO_ROUNDS; ++$round) {
        $production = $corpus . '/' . $generation['production_file'];
        file_put_contents($production, (string) file_get_contents($production) . sprintf((string) $generation['edit'], $round));
        $incremental = $scan('incremental', $limit * 1.5 + 30);
        $result = jsonObject($incremental);
        $error = benchmarkScenarioParseError($scenario, $round, $reached, $result['data']['parsed_files'] ?? null);
        if ($error !== null) {
            throw new RuntimeException($error);
        }
        $stages[] = $result['data']['metrics']['stages_ms'] ?? [];
        $rounds[] = $incremental['seconds'];
    }
    $sorted = $rounds;
    sort($sorted);
    $median = $sorted[intdiv(count($sorted), 2)];

    return [
        'corpus_sha256' => $generation['corpus_sha256'],
        'cold_scan_seconds' => $cold['seconds'],
        'incremental_scan_seconds' => $median,
        'incremental_parsed_files' => $reached,
        'incremental_rounds_seconds' => $rounds,
        'incremental_stages_ms' => $stages[array_search($median, $rounds, true)],
    ];
}

$budgetPath = BENCHMARK_ROOT . '/benchmarks/budgets.json';
$reportPath = BENCHMARK_ROOT . '/coverage/benchmarks/report.json';
$positional = [];
require_once __DIR__ . '/lib/cli-arguments.php';
// $argv is only a global where register_argc_argv is on (see tools/lib/cli-arguments.php).
$argv = cliArguments();
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--budgets=') && $argument !== '--budgets=') {
        $budgetPath = substr($argument, strlen('--budgets='));
    } elseif (str_starts_with($argument, '--report=') && $argument !== '--report=') {
        $reportPath = substr($argument, strlen('--report='));
    } elseif (!str_starts_with($argument, '--') && count($positional) < 2) {
        $positional[] = $argument;
    } else {
        fwrite(STDERR, "usage: tools/benchmark [--budgets=FILE] [--report=FILE]\n");
        exit(2);
    }
}
$budgetPath = $positional[0] ?? $budgetPath;
$reportPath = $positional[1] ?? $reportPath;
[$budgets, $budgetErrors] = readBudgetFile($budgetPath);
if ($budgets !== null) {
    $budgetErrors = benchmarkBudgetErrors($budgets);
}
if ($budgetErrors !== []) {
    fwrite(STDERR, "Invalid benchmark budget file.\n");
    foreach ($budgetErrors as $error) {
        fwrite(STDERR, sprintf("benchmark budgets %s: %s\n", $budgetPath, $error));
    }
    exit(2);
}
// A file with no sizes runs no scan, and a run that measured nothing proves nothing.
if ($budgets['sizes'] === []) {
    fwrite(STDERR, sprintf("benchmark budgets %s: no sizes measured; the file must declare at least one.\n", $budgetPath));
    exit(1);
}
$memoryMeasurable = is_dir('/proc/self');
if (!$memoryMeasurable) {
    fwrite(STDERR, "peak_rss_mb NOT measured: there is no /proc to read resident memory from.\n");
}

$temporaryRoot = sys_get_temp_dir() . '/knossos-benchmark-' . bin2hex(random_bytes(8));
$results = [];
$violations = [];
try {
    foreach ($budgets['sizes'] as $size => $limits) {
        $corpus = $temporaryRoot . '/' . $size . '/corpus';
        $database = $temporaryRoot . '/' . $size . '/knossos.sqlite';
        $count = $limits['files_per_language'];
        $generated = runMeasured([PHP_BINARY, __DIR__ . '/generate-benchmark-corpus.php', $corpus, (string) $count], 30);
        $generation = jsonObject($generated);

        $cold = runMeasured([
            PHP_BINARY, BENCHMARK_ROOT . '/bin/knossos', 'scan', $corpus,
            '--mode=full', '--snapshot-retention=2', '--db=' . $database, '--json',
        ], (float) $limits['cold_scan_seconds'] * 1.5 + 5);
        $scan = jsonObject($cold);

        $changedFile = $corpus . '/typescript/src/service-0000.ts';
        file_put_contents($changedFile, (string) file_get_contents($changedFile) . "\nexport const benchmarkChange = true;\n");
        $incremental = runMeasured([
            PHP_BINARY, BENCHMARK_ROOT . '/bin/knossos', 'scan', $corpus,
            '--mode=incremental', '--snapshot-retention=2', '--db=' . $database, '--json',
        ], (float) $limits['incremental_scan_seconds'] * 1.5 + 5);
        $incrementalScan = jsonObject($incremental);

        $query = runMeasured([
            PHP_BINARY, BENCHMARK_ROOT . '/bin/knossos', 'architecture-summary', (string) $scan['project_id'],
            '--limit=100', '--db=' . $database, '--json',
        ], (float) $limits['query_seconds'] * 1.5 + 2);
        jsonObject($query);

        clearstatcache(true, $database);
        $sqliteBytes = is_file($database) ? (int) filesize($database) : 0;
        $peakRss = max($cold['peak_rss_bytes'], $incremental['peak_rss_bytes'], $query['peak_rss_bytes']);
        $actual = [
            'source_files' => $generation['generated_source_files'],
            'corpus_sha256' => $generation['corpus_sha256'],
            'cold_scan_seconds' => $cold['seconds'],
            'incremental_scan_seconds' => $incremental['seconds'],
            'query_seconds' => $query['seconds'],
            'peak_rss_mb' => round($peakRss / 1_048_576, 2),
            'sqlite_mb' => round($sqliteBytes / 1_048_576, 2),
            'cold_stages_ms' => $scan['data']['metrics']['stages_ms'] ?? [],
            'incremental_stages_ms' => $incrementalScan['data']['metrics']['stages_ms'] ?? [],
        ];
        $results[$size] = ['limits' => $limits, 'actual' => $actual];
        $violations = [...$violations, ...benchmarkViolations((string) $size, $limits, $actual, $memoryMeasurable)];
        fwrite(STDOUT, sprintf(
            "%s: cold=%.2fs incremental=%.2fs query=%.2fs rss=%.2fMiB sqlite=%.2fMiB\n",
            $size,
            $actual['cold_scan_seconds'],
            $actual['incremental_scan_seconds'],
            $actual['query_seconds'],
            $actual['peak_rss_mb'],
            $actual['sqlite_mb'],
        ));
    }

    $scenarios = [];
    foreach ($budgets['scenarios'] ?? [] as $scenario => $limits) {
        $limit = (float) $limits['incremental_scan_seconds'];
        $actual = runTestFidelityScenario((string) $scenario, $temporaryRoot . '/scenario-' . $scenario, $limit);
        $scenarios[$scenario] = ['limits' => $limits, 'actual' => $actual];
        $violations = [...$violations, ...benchmarkScenarioViolations((string) $scenario, $limit, $actual['incremental_scan_seconds'])];
        fwrite(STDOUT, sprintf("%s: cold=%.2fs incremental=%.2fs (median of %d)\n", $scenario, $actual['cold_scan_seconds'], $actual['incremental_scan_seconds'], SCENARIO_ROUNDS));
    }

    $report = [
        'schema_version' => 1,
        'passed' => $violations === [],
        'environment' => [
            'php' => PHP_VERSION,
            'platform' => PHP_OS_FAMILY . '-' . php_uname('m'),
        ],
        'results' => $results,
        'scenarios' => $scenarios,
        'violations' => $violations,
    ];
    writeJsonReport($reportPath, $report, 'benchmark report', 0o755);
    if ($violations !== []) {
        fwrite(STDERR, implode("\n", $violations) . "\n");
    }
} finally {
    removeTree($temporaryRoot);
}
// Outside the try: exit() ends the script without running a finally block,
// so exiting from inside it left the whole corpus in the temp directory.
exit($violations === [] ? 0 : 1);
