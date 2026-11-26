<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity\Facts;

use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\EdgeFact;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\TestFidelity\Facts\FieldLists;
use Knossos\TestFidelity\TestFidelityGraph;
use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertSame;

/**
 * A type's whole field list over the graph: composed along the scan's own
 * inheritance edges for a worker that sends own fields only, and taken as sent
 * from the TypeScript worker, whose checker merged it already.
 */
final class FieldListsTest extends KnossosTestCase
{
    /** @param array<string, mixed> $attributes */
    private static function node(string $localId, array $attributes): NodeFact
    {
        $name = substr($localId, (int) strrpos($localId, ':') + 1);

        return new NodeFact($localId, 'class', $name, $name, Origin::Ast, Confidence::Certain, new Evidence('src/a', 1, 9), $attributes);
    }

    /** @return array<string, mixed> */
    private static function fields(string ...$names): array
    {
        return ['fields' => array_map(static fn(string $name): array => ['name' => $name, 'required' => true, 'line' => 2], $names)];
    }

    /**
     * @param list<NodeFact> $nodes
     * @param list<array{string, NodeFact, string}> $edges kind, source, target reference
     */
    private static function graph(array $nodes, array $edges): TestFidelityGraph
    {
        return TestFidelityGraph::fromContributions([new ScanContribution('knossos.test:file:src/a', $nodes, array_map(
            static fn(array $edge): EdgeFact => new EdgeFact($edge[0], $edge[1]->localId, $edge[2], Origin::Ast, Confidence::Certain, $edge[1]->evidence),
            $edges,
        ))]);
    }

    public function testATypeScriptListIsTakenAsSent(): void
    {
        $child = self::node('ts:class:src/a.ts#Child', self::fields('id', 'name'));
        $graph = self::graph([$child], [['extends', $child, 'ts:external_symbol:lib#Base']]);

        assertSame(['id', 'name'], array_keys(FieldLists::of($graph, $child)?->fields() ?? []));
    }

    public function testANodeWithoutAListSaysNothing(): void
    {
        $plain = self::node('php:class:App\\Plain', []);

        assertSame(null, FieldLists::of(self::graph([$plain], []), $plain));
    }

    public function testAnAncestorWithoutAListLeavesTheWholeUnknown(): void
    {
        $base = self::node('php:class:App\\Base', []);
        $child = self::node('php:class:App\\Child', self::fields('id'));
        $graph = self::graph([$base, $child], [['extends', $child, 'php:class:App\\Base']]);

        assertSame(null, FieldLists::of($graph, $child));
    }

    public function testTheNearestDeclarationWinsAndAnInterfaceIsNotWalked(): void
    {
        $base = self::node('php:class:App\\Base', ['fields' => [['name' => 'id', 'type' => 'int', 'required' => true, 'line' => 4]]]);
        $child = self::node('php:class:App\\Child', ['fields' => [['name' => 'id', 'type' => 'string', 'required' => false, 'line' => 7]]]);
        $graph = self::graph([$base, $child], [['extends', $child, 'php:class:App\\Base'], ['implements', $child, 'php:interface:Vendor\\Contract']]);

        $list = FieldLists::of($graph, $child);

        assertNotNull($list);
        assertSame(['id' => ['type' => 'string', 'required' => false, 'line' => 7]], $list->fields());
    }
}
