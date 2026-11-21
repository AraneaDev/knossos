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
     * @param string $framework the framework the double is of
     */
    public static function graph(array $methods, array $members, array $target = ['kind' => 'class', 'export' => 'UserService'], array $extra = [], array $extraEdges = [], string $targetKind = 'class', ?array $targetAttributes = null, string $framework = 'vitest'): TestFidelityGraph
    {
        $names = array_map(static fn(NodeFact $method): string => $method->displayName, $methods);
        sort($names, SORT_STRING);
        $class = self::node($targetKind, 'src/UserService.ts#UserService', 'UserService', $targetAttributes ?? [$targetKind === 'module' ? 'exports' : 'member_names' => $names]);
        $edges = array_map(static fn(NodeFact $method): EdgeFact => self::edge('contains', $class, $method->localId), $methods);
        $double = self::node('test_double', 'tests/a.test.ts#double:6:3', 'vi.spyOn', [
            'framework' => $framework, 'pattern' => 'vi.spyOn', 'target' => $target, 'automock' => false, 'invocations' => 0, 'members' => $members,
        ], 6);

        return TestFidelityGraph::fromContributions([
            new ScanContribution('knossos.typescript:file:src/UserService.ts', [$class, ...$methods, ...$extra], [...$edges, ...$extraEdges]),
            new ScanContribution('knossos.typescript:file:tests/a.test.ts', [$double], [self::edge('doubles', $double, $class->localId)]),
        ]);
    }

    /**
     * A PHP class-like node, as the PHP worker emits one: `php:<kind>:<FQCN>` in `$file`.
     *
     * @param array<string, mixed> $attributes
     */
    public static function phpType(string $kind, string $fqcn, array $attributes = [], string $file = 'src/UserService.php'): NodeFact
    {
        return new NodeFact('php:' . $kind . ':' . $fqcn, $kind, $fqcn, substr((string) strrchr('\\' . $fqcn, '\\'), 1), Origin::Ast, Confidence::Certain, new Evidence($file, 1, 1), $attributes);
    }

    /**
     * A PHP method node of `$owner`, its canonical name `<FQCN>::<name>`.
     *
     * @param array<string, mixed> $attributes
     */
    public static function phpMethod(NodeFact $owner, string $name, array $attributes = []): NodeFact
    {
        return new NodeFact('php:method:' . $owner->canonicalName . '::' . $name, 'method', $owner->canonicalName . '::' . $name, $name, Origin::Ast, Confidence::Certain, $owner->evidence, $attributes);
    }

    /**
     * A PHP class `App\UserService` with the given methods, and a PHPUnit double
     * whose `doubles` edge names `php:class:<$doubled>`, as the PHP worker
     * emits every one whatever the target's kind.
     *
     * @param list<array{string, array<string, mixed>}> $methods name and attributes of each
     * @param list<array<string, mixed>> $members
     * @param array<string, mixed> $doubleAttributes overrides of the double's attributes
     * @param array<string, mixed>|null $targetAttributes the class's attributes; by default the complete name list of its methods
     * @param list<NodeFact> $extra further production nodes
     * @param list<EdgeFact> $extraEdges
     */
    public static function phpGraph(array $methods, array $members, array $doubleAttributes = [], string $targetKind = 'class', ?array $targetAttributes = null, array $extra = [], array $extraEdges = [], string $doubled = 'App\\UserService'): TestFidelityGraph
    {
        $class = self::phpType($targetKind, 'App\\UserService', $targetAttributes ?? ['member_names' => array_map(static fn(array $method): string => $method[0], $methods)]);
        $nodes = array_map(static fn(array $method): NodeFact => self::phpMethod($class, $method[0], $method[1]), $methods);
        $double = new NodeFact('php:test_double:tests/UserServiceTest.php#double:6:3', 'test_double', 'tests/UserServiceTest.php#double:6:3', 'createMock', Origin::Ast, Confidence::Certain, new Evidence('tests/UserServiceTest.php', 6, 6), [
            'framework' => 'phpunit', 'pattern' => 'createMock', 'target' => ['kind' => 'class', 'export' => $doubled], 'automock' => false, 'invocations' => 0, 'members' => $members, ...$doubleAttributes,
        ]);

        return TestFidelityGraph::fromContributions([
            new ScanContribution('knossos.php:file:src/UserService.php', [$class, ...$nodes, ...$extra], [...array_map(static fn(NodeFact $method): EdgeFact => self::edge('contains', $class, $method->localId), $nodes), ...$extraEdges]),
            new ScanContribution('knossos.php:file:tests/UserServiceTest.php', [$double], [self::edge('doubles', $double, 'php:class:' . $doubled)]),
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
     * @param list<array<string, mixed>> $calledWith the call matchers on the member, as the wire states them
     * @return array<string, mixed>
     */
    public static function stub(string $name, ?array $params = null, array $returns = [], array $calledWith = []): array
    {
        return ['name' => $name, 'line' => 7, 'api' => 'spyOn', 'returns' => $returns, 'called_with' => $calledWith] + ($params === null ? [] : ['params' => $params]);
    }
}
