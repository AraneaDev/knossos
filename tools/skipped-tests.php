<?php

declare(strict_types=1);

/**
 * Refuse a PHPUnit run that skipped more tests than coverage-budgets.json allows.
 *
 *   php tools/skipped-tests.php [--budgets=FILE] JUNIT...
 *
 * tools/coverage runs this after an unsharded run; a sharded run is counted by
 * tools/coverage-merge.php, over every shard's log. Exit 0 passes, 1 refuses,
 * 64 means it was called wrong.
 */

require __DIR__ . '/lib/skipped-tests.php';

$budgetFile = dirname(__DIR__) . '/coverage-budgets.json';
$logs = [];
require_once __DIR__ . '/lib/cli-arguments.php';
// $argv is only a global where register_argc_argv is on (see tools/lib/cli-arguments.php).
$argv = cliArguments();
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--budgets=') && strlen($argument) > 10) {
        $budgetFile = substr($argument, 10);
    } elseif (!str_starts_with($argument, '--')) {
        $logs[] = $argument;
    } else {
        $logs = [];
        break;
    }
}
if ($logs === []) {
    fwrite(STDERR, "usage: php tools/skipped-tests.php [--budgets=FILE] JUNIT...\n");
    exit(64);
}

[$budgets, $errors] = coverageBudgetFile($budgetFile, null);
$skipped = [];
if ($budgets !== null && $errors === []) {
    [$skipped, $errors] = skippedTestsIn($logs);
    $errors = $skipped === null ? $errors : skippedTestsOverBudget($budgets, $skipped);
}
if ($budgets === null || $errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, "skipped tests: {$error}\n");
    }
    exit(1);
}
printf("skipped tests: %d of %d allowed\n", count($skipped ?? []), (int) $budgets[SKIPPED_TESTS_KEY]);
