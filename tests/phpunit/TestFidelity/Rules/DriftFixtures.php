<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity\Rules;

use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\EdgeFact;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\TestFidelity\TestFidelityGraph;

/**
 * In-memory facts for the drift rule tests: a `UserService` class in
 * `src/UserService.ts` and one test double replacing it.
 */
final class DriftFixtures
{
    /** @param array<string, mixed> $attributes */
    public static function node(string $kind, string $canonical, string $display, array $attributes = [], int $line = 1): NodeFact
    {
        $file = explode('#', $canonical)[0];

        return new NodeFact('ts:' . $kind . ':' . $canonical, $kind, $canonical, $display, Origin::Ast, Confidence::Certain, new Evidence($file, $line, $line), $attributes);
    }

    public static function edge(string $kind, NodeFact $source, string $target): EdgeFact
    {
        return new EdgeFact($kind, $source->localId, $target, Origin::Ast, Confidence::Certain, $source->evidence);
    }

    /**
     * A class with the given method nodes and a double stubbing `$members`.
     *
     * @param list<NodeFact> $methods
     * @param list<array<string, mixed>> $members
     * @param array<string, string> $target
     * @param list<NodeFact> $extra further production nodes, contributed with the class
     * @param list<EdgeFact> $extraEdges
     * @param array<string, mixed>|null $targetAttributes the target node's attributes; by default the complete name list of what it contains
     */
    public static function graph(array $methods, array $members, array $target = ['kind' => 'class', 'export' => 'UserService'], array $extra = [], array $extraEdges = [], string $targetKind = 'class', ?array $targetAttributes = null): TestFidelityGraph
    {
        $names = array_map(static fn(NodeFact $method): string => $method->displayName, $methods);
        sort($names, SORT_STRING);
        $class = self::node($targetKind, 'src/UserService.ts#UserService', 'UserService', $targetAttributes ?? [$targetKind === 'module' ? 'exports' : 'member_names' => $names]);
        $edges = array_map(static fn(NodeFact $method): EdgeFact => self::edge('contains', $class, $method->localId), $methods);
        $double = self::node('test_double', 'tests/a.test.ts#double:6:3', 'vi.spyOn', [
            'framework' => 'vitest', 'pattern' => 'vi.spyOn', 'target' => $target, 'automock' => false, 'invocations' => 0, 'members' => $members,
        ], 6);

        return TestFidelityGraph::fromContributions([
            new ScanContribution('knossos.typescript:file:src/UserService.ts', [$class, ...$methods, ...$extra], [...$edges, ...$extraEdges]),
            new ScanContribution('knossos.typescript:file:tests/a.test.ts', [$double], [self::edge('doubles', $double, $class->localId)]),
        ]);
    }

    /** A double with no `doubles` edge, replacing a module by specifier. */
    public static function moduleGraph(string $specifier): TestFidelityGraph
    {
        $double = self::node('test_double', 'tests/a.test.ts#double:2:1', 'vi.mock', [
            'framework' => 'vitest', 'pattern' => 'vi.mock', 'target' => ['kind' => 'module', 'specifier' => $specifier], 'automock' => true, 'invocations' => 0, 'members' => [],
        ], 2);

        return TestFidelityGraph::fromContributions([new ScanContribution('knossos.typescript:file:tests/a.test.ts', [$double], [])]);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function method(string $name, array $attributes = []): NodeFact
    {
        return self::node('method', 'src/UserService.ts#UserService::' . $name, $name, $attributes);
    }

    /**
     * @param list<mixed>|null $params
     * @param list<array<string, mixed>> $returns
     * @return array<string, mixed>
     */
    public static function stub(string $name, ?array $params = null, array $returns = []): array
    {
        return ['name' => $name, 'line' => 7, 'api' => 'spyOn', 'returns' => $returns] + ($params === null ? [] : ['params' => $params]);
    }
}
