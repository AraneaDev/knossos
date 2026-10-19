<?php

declare(strict_types=1);

/**
 * Refuse a coverage budget file whose floors could not act, before any floor runs.
 *
 *   php tools/check-coverage-budgets.php FILE
 *
 * tools/coverage runs this first, so a missing or invalid floor stops the
 * profile in a second rather than after the PHPUnit run, and before c8,
 * vitest, coverage.py or cargo-llvm-cov is handed a value it would read as no
 * floor at all. The PHP component floors are matched against the measured
 * components later, by tools/pcov-report.php. Exit 0 passes, 1 refuses, 2
 * means it was called wrong.
 */

require __DIR__ . '/lib/coverage-budgets.php';

require_once __DIR__ . '/lib/cli-arguments.php';
// $argv is only a global where register_argc_argv is on (see tools/lib/cli-arguments.php).
$argv = cliArguments();
$argc = count($argv);
if ($argc !== 2) {
    fwrite(STDERR, "usage: php tools/check-coverage-budgets.php FILE\n");
    exit(2);
}
[, $errors] = coverageBudgetFile($argv[1], null);
if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, sprintf("coverage budgets %s: %s\n", $argv[1], $error));
    }
    exit(1);
}
printf("coverage budgets %s: every floor is a number from 0 to 100.\n", $argv[1]);
