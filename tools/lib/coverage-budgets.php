<?php

declare(strict_types=1);

/**
 * Validation of coverage-budgets.json, shared by tools/coverage and tools/pcov-report.php.
 *
 * Each runtime's floor is handed to a different tool: c8 and vitest for
 * TypeScript, coverage.py for Python, cargo-llvm-cov for Rust, pcov-report for
 * PHP. None of them refuses a floor that is absent. c8 reads a missing key as
 * NaN and never fails, vitest skips a threshold it is not given, and
 * pcov-report read a missing component floor as 0. So the file is checked here,
 * before any floor runs, and a floor that could not act stops the run.
 */

require_once __DIR__ . '/budget-numbers.php';

/** Every floor the coverage profile reads, by dotted key. The PHP component floors are checked separately. */
const COVERAGE_FLOOR_KEYS = [
    'php.aggregate_lines',
    'typescript.lines',
    'typescript.branches',
    'typescript.functions',
    'typescript.statements',
    'python.lines',
    'rust.lines',
    'mod.lines',
    'mod.branches',
    'mod.functions',
    'mod.statements',
];

/**
 * The most PHPUnit tests a coverage run may skip, by key.
 *
 * Not a percentage: a count, compared with the skipped tests in the run's JUnit
 * logs (tools/coverage-merge.php). A test that skips itself when a tool or a
 * permission is missing passes without asserting anything, and nothing used to
 * count them, so a suite could lose whole branches to skips and stay green.
 */
const SKIPPED_TESTS_KEY = 'skipped_tests';

/**
 * Why the coverage budgets cannot be enforced as they stand; empty when they can.
 *
 * Every floor is a percentage, so a JSON number from 0 to 100. With
 * `$measuredComponents` given, every PHP component the run measured needs a
 * floor, and every floor needs a component, since a floor naming a component no
 * file belongs to never compares anything. With null, as before the PHP run has
 * been measured, the component floors are checked for their values only.
 *
 * @param list<string>|null $measuredComponents
 * @return list<string>
 */
function coverageBudgetErrors(mixed $budgets, ?array $measuredComponents): array
{
    if (!is_array($budgets)) {
        return ['the coverage budgets must be a JSON object.'];
    }
    $errors = [];
    foreach (COVERAGE_FLOOR_KEYS as $key) {
        $error = budgetNumberError($key, budgetAt($budgets, $key), 0.0, 100.0);
        if ($error !== null) {
            $errors[] = $error;
        }
    }
    $skipped = budgetAt($budgets, SKIPPED_TESTS_KEY);
    $error = budgetNumberError(SKIPPED_TESTS_KEY, $skipped, 0.0);
    if ($error === null && !is_int($skipped)) {
        $error = sprintf('%s is %s; it must be a whole number of tests.', SKIPPED_TESTS_KEY, json_encode($skipped));
    }
    if ($error !== null) {
        $errors[] = $error;
    }
    $components = budgetAt($budgets, 'php.components');
    if (!is_array($components) || ($components !== [] && array_is_list($components))) {
        $errors[] = 'php.components is missing; it must map each PHP component to its floor.';
        $components = [];
    }
    foreach ($components as $component => $floor) {
        $error = budgetNumberError('php.components.' . $component, $floor, 0.0, 100.0);
        if ($error !== null) {
            $errors[] = $error;
        }
    }
    if ($measuredComponents === null) {
        return $errors;
    }
    foreach ($measuredComponents as $component) {
        if (!array_key_exists($component, $components)) {
            $errors[] = sprintf('php.components.%s is missing: the component is measured but has no floor.', $component);
        }
    }
    foreach (array_keys($components) as $component) {
        if (!in_array((string) $component, $measuredComponents, true)) {
            $errors[] = sprintf('php.components.%s has a floor, but no measured file belongs to that component.', $component);
        }
    }

    return $errors;
}

/**
 * Read the coverage budget file at `$path` and say why it cannot be enforced; empty when it can.
 *
 * @param list<string>|null $measuredComponents as for {@see coverageBudgetErrors()}
 * @return array{0: array<mixed>|null, 1: list<string>} the budgets, and the reasons they cannot act
 */
function coverageBudgetFile(string $path, ?array $measuredComponents): array
{
    [$budgets, $errors] = readBudgetFile($path);
    if ($budgets === null) {
        return [null, $errors];
    }

    return [$budgets, coverageBudgetErrors($budgets, $measuredComponents)];
}
