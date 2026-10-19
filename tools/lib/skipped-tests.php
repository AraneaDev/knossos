<?php

declare(strict_types=1);

/**
 * The PHPUnit skip budget: how many tests a coverage run skipped, against the
 * most coverage-budgets.json allows.
 *
 * A test that marks itself skipped, because a tool is missing or the process
 * runs as root, passes without asserting anything, and PHPUnit's own exit
 * status does not change for it. Nothing counted them, so a suite could lose a
 * branch to a skip and stay green. The count comes from the JUnit logs the run
 * already writes; an incomplete test is logged the same way and counts too.
 */

require_once __DIR__ . '/coverage-budgets.php';

/**
 * Every skipped test in the given JUnit logs, as `Class::method`, or null with
 * the reason a log could not be read.
 *
 * @param list<string> $junitFiles
 * @return array{0: list<string>|null, 1: list<string>}
 */
function skippedTestsIn(array $junitFiles): array
{
    $skipped = [];
    $errors = [];
    foreach ($junitFiles as $file) {
        $document = new DOMDocument();
        if (!is_file($file) || !@$document->load($file)) {
            $errors[] = "{$file} is not a readable JUnit log";
            continue;
        }
        foreach ((new DOMXPath($document))->query('//testcase[skipped]') ?: [] as $case) {
            if ($case instanceof DOMElement) {
                $skipped[] = $case->getAttribute('class') . '::' . $case->getAttribute('name');
            }
        }
    }
    if ($errors !== []) {
        return [null, $errors];
    }
    sort($skipped, SORT_STRING);

    return [$skipped, []];
}

/**
 * Why the skipped tests exceed the budget in `$budgets`, or an empty list when they do not.
 *
 * @param array<mixed> $budgets a budget file that passed {@see coverageBudgetErrors()}
 * @param list<string> $skipped
 * @return list<string>
 */
function skippedTestsOverBudget(array $budgets, array $skipped): array
{
    $budget = (int) $budgets[SKIPPED_TESTS_KEY];
    if (count($skipped) <= $budget) {
        return [];
    }
    $errors = [sprintf(
        '%d tests were skipped, over the %s budget of %d; make them run, or raise the budget in a reviewed change that says why:',
        count($skipped),
        SKIPPED_TESTS_KEY,
        $budget,
    )];
    foreach ($skipped as $test) {
        $errors[] = '  skipped: ' . $test;
    }

    return $errors;
}
