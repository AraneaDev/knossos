<?php

declare(strict_types=1);

/**
 * The checks tools/benchmark.php holds a run to: a budget file whose every
 * limit is a number, and each size's measurements against those limits.
 *
 * The runner cast each limit with `(float)`, so a missing one read as 0 and a
 * quoted one as whatever it started with, and a file with no sizes ran nothing
 * and passed. Peak memory comes from /proc, and a reading of 0 there means the
 * measurement failed, not that the scans used no memory.
 */

require_once __DIR__ . '/budget-numbers.php';

/** The limits every size declares, each a maximum the measurement must not exceed. */
const BENCHMARK_METRICS = ['cold_scan_seconds', 'incremental_scan_seconds', 'query_seconds', 'peak_rss_mb', 'sqlite_mb'];

/**
 * The scenarios a budget file may hold, each with its own corpus and the one
 * limit it declares: the median incremental scan after an edit. Each maps to
 * the language of its corpus, as `generate-test-fidelity-corpus.php` takes it.
 */
const BENCHMARK_SCENARIOS = ['test_fidelity_rescan_one_file' => 'typescript', 'test_fidelity_rescan_one_file_php' => 'php', 'test_fidelity_rescan_one_file_python' => 'python', 'test_fidelity_rescan_one_file_rust' => 'rust'];

/**
 * The on-request scenarios, each with the one limit it declares: the median
 * of one on-request query over a size profile's corpus with what that tool
 * reads added (`generate-on-request-corpus.php`). Each names its profile's
 * file count, the corpus options, the command and its arguments, and how
 * many items, and fixture files checked, a run must report for it to measure
 * the work it is for. The `audit_tests` corpus is committed to git and its
 * first two services edited, so `STALE_SINCE` reads a git-diff too; it gives
 * 14 `OVER_MOCKING` (the first and every fifth TypeScript and Python test),
 * 9 `STALE_SINCE` (the three tests per language doubling an edited service)
 * and one scan-tier `MOCKS_SUBJECT` (PHP's first test doubles its own
 * subject), 24 in all. The fixtures corpus gives one `FIXTURE_DRIFT` per
 * tenth file, 50 in all.
 */
const BENCHMARK_QUERY_SCENARIOS = [
    'audit_tests_on_request_medium' => [
        'files_per_language' => 30,
        'corpus' => ['--tests'],
        'git' => true,
        'command' => 'audit-tests',
        'arguments' => ['--tier=all', '--scope=git-diff', '--base-ref=HEAD', '--limit=200'],
        'least_items' => 24,
        'fixtures_checked' => null,
    ],
    'check_fixtures_medium' => [
        'files_per_language' => 30,
        'corpus' => ['--fixtures=500'],
        'git' => false,
        'command' => 'check-fixtures',
        'arguments' => ['--limit=200'],
        'least_items' => 50,
        'fixtures_checked' => 500,
    ],
];

/**
 * Why the benchmark budgets cannot be enforced as they stand; empty when they can.
 *
 * @return list<string>
 */
function benchmarkBudgetErrors(mixed $budgets): array
{
    if (!is_array($budgets) || ($budgets['schema_version'] ?? null) !== 1 || !is_array($budgets['sizes'] ?? null)) {
        return ['the file must hold schema_version 1 and an object of sizes.'];
    }
    $errors = [];
    foreach ($budgets['sizes'] as $size => $limits) {
        if (!is_string($size) || !is_array($limits)) {
            $errors[] = sprintf('sizes.%s must be an object of limits.', (string) $size);
            continue;
        }
        $count = $limits['files_per_language'] ?? null;
        if (!is_int($count) || $count < 1) {
            $errors[] = sprintf('sizes.%s.files_per_language must be a whole number at least 1.', $size);
        }
        foreach (BENCHMARK_METRICS as $metric) {
            $error = budgetNumberError(sprintf('sizes.%s.%s', $size, $metric), $limits[$metric] ?? null, 0.0);
            if ($error !== null) {
                $errors[] = $error;
            }
        }
    }

    return [...$errors, ...benchmarkScenarioErrors($budgets['scenarios'] ?? [])];
}

/**
 * Why the optional scenarios cannot be enforced; empty when they can.
 *
 * @return list<string>
 */
function benchmarkScenarioErrors(mixed $scenarios): array
{
    if (!is_array($scenarios) || ($scenarios !== [] && array_is_list($scenarios))) {
        return ['scenarios must be an object of scenarios.'];
    }
    $errors = [];
    foreach ($scenarios as $name => $limits) {
        if (!isset(BENCHMARK_SCENARIOS[$name]) && !isset(BENCHMARK_QUERY_SCENARIOS[$name])) {
            $errors[] = sprintf('scenarios.%s is not a scenario the benchmark runs (%s).', (string) $name, implode(', ', [...array_keys(BENCHMARK_SCENARIOS), ...array_keys(BENCHMARK_QUERY_SCENARIOS)]));
            continue;
        }
        $metric = isset(BENCHMARK_SCENARIOS[$name]) ? 'incremental_scan_seconds' : 'query_seconds';
        $error = budgetNumberError(sprintf('scenarios.%s.%s', $name, $metric), is_array($limits) ? ($limits[$metric] ?? null) : null, 0.0);
        if ($error !== null) {
            $errors[] = $error;
        }
    }

    return $errors;
}

/**
 * Each limit one size's measurements broke.
 *
 * `$memoryMeasurable` says whether /proc was there to read peak memory from;
 * where it was, a peak of 0 is a failed measurement and is refused as one.
 *
 * @param array<string, int|float> $limits @param array<string, mixed> $actual
 * @return list<string>
 */
function benchmarkViolations(string $size, array $limits, array $actual, bool $memoryMeasurable): array
{
    $violations = [];
    foreach (BENCHMARK_METRICS as $metric) {
        if ((float) $actual[$metric] > (float) $limits[$metric]) {
            $violations[] = sprintf('%s.%s %.2f exceeds %.2f', $size, $metric, $actual[$metric], $limits[$metric]);
        }
    }
    if ($memoryMeasurable && (float) $actual['peak_rss_mb'] <= 0.0) {
        $violations[] = sprintf('%s.peak_rss_mb was not measured: /proc reported no resident memory for the scans', $size);
    }

    return $violations;
}

/**
 * The scenario's limit its median incremental scan broke, if it broke it.
 *
 * @return list<string>
 */
function benchmarkScenarioViolations(string $scenario, float $limit, float $seconds, string $metric = 'incremental_scan_seconds'): array
{
    return $seconds > $limit ? [sprintf('scenarios.%s.%s %.2f exceeds %.2f', $scenario, $metric, $seconds, $limit)] : [];
}

/**
 * Why an on-request scenario's query does not measure what it is for, or
 * null when it does: a run that reported no item, or checked other than the
 * fixture files its corpus holds, would time a cheaper answer and pass for
 * the wrong reason.
 */
function benchmarkQueryScenarioError(string $scenario, int $round, mixed $items, mixed $checked): ?string
{
    $expected = BENCHMARK_QUERY_SCENARIOS[$scenario];
    if (!is_int($items) || $items < $expected['least_items']) {
        return sprintf('scenarios.%s round %d reported %s items, fewer than the %d its corpus gives; it would not measure the query it is for.', $scenario, $round + 1, is_int($items) ? (string) $items : 'an unknown number of', $expected['least_items']);
    }
    if ($expected['fixtures_checked'] !== null && $checked !== $expected['fixtures_checked']) {
        return sprintf('scenarios.%s round %d checked %s fixture files, not the %d its corpus holds; it would not measure the query it is for.', $scenario, $round + 1, is_int($checked) ? (string) $checked : 'an unknown number of', $expected['fixtures_checked']);
    }

    return null;
}

/**
 * Why a scenario's rescan does not measure what it is for, or null when it
 * does: the edit has to make the scan parse the files its corpus says it
 * reaches (the production file, and for TypeScript, Python and Rust every
 * test file again), or the median would time a rescan of fewer files and pass
 * for the wrong reason.
 */
function benchmarkScenarioParseError(string $scenario, int $round, int $expected, mixed $parsed): ?string
{
    return $parsed === $expected ? null : sprintf(
        'scenarios.%s round %d parsed %s files, not the %d its edit reaches; it would not measure the rescan it is for.',
        $scenario,
        $round + 1,
        is_int($parsed) ? (string) $parsed : 'an unknown number of',
        $expected,
    );
}
