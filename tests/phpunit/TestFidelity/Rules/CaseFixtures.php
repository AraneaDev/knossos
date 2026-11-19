<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity\Rules;

use Knossos\Scanner\Protocol\EdgeFact;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\TestFidelity\TestFidelityGraph;

/**
 * In-memory facts for the tautology and mocks-subject rule tests: test cases
 * with their assertions, and the doubles they make, in `tests/a.test.ts`.
 */
final class CaseFixtures
{
    public const FILE = 'tests/a.test.ts';

    /**
     * A `test_case` node.
     *
     * @param list<array<string, mixed>> $assertions
     * @param list<string> $subjects
     */
    public static function testCase(array $assertions, int $productionCalls = 0, array $subjects = [], int $line = 10, string $file = self::FILE): NodeFact
    {
        return DriftFixtures::node('test_case', $file . '#test:' . $line, 'does a thing', [
            'name' => 'does a thing', 'production_calls' => $productionCalls, 'subjects' => $subjects, 'assertions' => $assertions,
        ], $line);
    }

    /**
     * One assertion of a test case.
     *
     * @param list<array<string, mixed>> $operands
     * @return array<string, mixed>
     */
    public static function assertion(string $api, array $operands, bool $negated = false, int $line = 12): array
    {
        return ['api' => $api, 'line' => $line, 'operands' => $operands] + ($negated ? ['negated' => true] : []);
    }

    /**
     * One operand: its source, and the optional fields as given.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public static function operand(string $source, array $fields = []): array
    {
        return ['source' => $source, ...$fields];
    }

    /**
     * A `test_double` node.
     *
     * @param array<string, string> $target
     * @param list<array<string, mixed>> $members
     */
    public static function double(int $line, string $pattern = 'vi.spyOn', array $target = ['kind' => 'instance-member', 'member' => 'total'], array $members = [], int $invocations = 0, ?string $testCase = null, string $file = self::FILE): NodeFact
    {
        return DriftFixtures::node('test_double', $file . '#double:' . $line . ':5', $pattern, [
            'framework' => 'vitest', 'pattern' => $pattern, 'target' => $target, 'automock' => false, 'invocations' => $invocations, 'members' => $members,
        ] + ($testCase === null ? [] : ['test_case' => $testCase]), $line);
    }

    /**
     * A member a double configures, pinned once per return given.
     *
     * @return array<string, mixed>
     */
    public static function member(string $name, int $pins = 1): array
    {
        $pin = ['api' => 'mockReturnValue', 'line' => 8, 'once' => false, 'assignable' => true, 'typed' => true];

        return ['name' => $name, 'line' => 8, 'api' => 'spyOn', 'returns' => array_fill(0, $pins, $pin)];
    }

    /**
     * A graph of test nodes and production nodes, each in its own contribution.
     *
     * @param list<NodeFact> $testNodes
     * @param list<NodeFact> $production
     * @param list<EdgeFact> $edges the test file's edges, `doubles` among them
     */
    public static function graph(array $testNodes, array $production = [], array $edges = []): TestFidelityGraph
    {
        return TestFidelityGraph::fromContributions([
            new ScanContribution('knossos.typescript:file:src', $production, []),
            new ScanContribution('knossos.typescript:file:' . self::FILE, $testNodes, $edges),
        ]);
    }
}
