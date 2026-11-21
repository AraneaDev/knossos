<?php

declare(strict_types=1);

namespace KnossosPhpScanner\TestFacts;

use PhpParser\Node;

/**
 * A test file's test facts, added to its other facts: its doubles, as
 * `test_double` nodes, and its tests, as `test_case` nodes, each double
 * made in a test naming that test.
 */
final class TestFileFacts
{
    private function __construct() {}

    /**
     * Add a test file's doubles and tests to its facts, or, when reading them
     * fails, none of them and a warning: the file's other facts are what test
     * impact, untested code and dead code read, and must not be lost with them.
     *
     * @param list<Node> $ast the file's syntax tree, its names resolved
     * @param list<array<string, mixed>> $nodes
     * @param list<array<string, mixed>> $edges
     * @param list<array<string, mixed>> $diagnostics
     * @return array{list<array<string, mixed>>, list<array<string, mixed>>, list<array<string, mixed>>}
     */
    public static function addTo(array $ast, string $root, string $relativePath, string $source, array $nodes, array $edges, array $diagnostics): array
    {
        try {
            $doubles = TestDoubleCollector::read($ast, $relativePath, $source);
            $cases = TestCaseCollector::read($ast, $root, $relativePath, $source, $doubles);
            foreach ($doubles->records() as $double) {
                $double->testCase = $cases->caseAround($double->node);
            }
            [$doubleNodes, $doubleEdges] = $doubles->facts();
            [$caseNodes, $caseEdges] = $cases->facts();
        } catch (\Throwable $failure) {
            $diagnostics[] = [
                'severity' => 'warning',
                'code' => 'PHP_TEST_FACTS_FAILED',
                'message' => 'The file\'s test doubles and test cases could not be read, so none are reported: ' . $failure->getMessage(),
                'evidence' => ['path' => $relativePath, 'start_line' => 1, 'end_line' => 1],
            ];

            return [$nodes, $edges, $diagnostics];
        }
        if ($doubleNodes === [] && $caseNodes === []) {
            return [$nodes, $edges, $diagnostics];
        }
        $moduleId = 'php:module:' . $relativePath;
        if (!in_array($moduleId, array_column($nodes, 'local_id'), true)) {
            // Not `executable`: a file of test classes runs only when a test runner loads it.
            $nodes[] = ['local_id' => $moduleId, 'kind' => 'module', 'canonical_name' => $relativePath, 'display_name' => basename($relativePath), 'origin' => 'ast', 'confidence' => 'certain',
                'evidence' => ['path' => $relativePath, 'start_line' => 1, 'end_line' => 1], 'attributes' => (object) []];
        }

        return [[...$nodes, ...$doubleNodes, ...$caseNodes], [...$edges, ...$doubleEdges, ...$caseEdges], $diagnostics];
    }
}
