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
