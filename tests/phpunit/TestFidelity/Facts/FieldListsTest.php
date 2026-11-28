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

use PHPUnit\Framework\Attributes\Group;

/**
 * A type's whole field list over the graph: composed along the scan's own
 * inheritance edges for a worker that sends own fields only, and taken as sent
 * from the TypeScript worker, whose checker merged it already.
 */
#[Group('test-fidelity')]
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

    public function testARustListIsTakenAsSentThroughItsTraitImpls(): void
    {
        $user = self::node('rust:class:crate::User', self::fields('id'));
        $graph = self::graph([$user], [['implements', $user, 'rust:interface:std::fmt::Display']]);

        assertSame(['id'], array_keys(FieldLists::of($graph, $user)?->fields() ?? []));
    }

    /** @return iterable<string, array{string}> */
    public static function deserializeImpls(): iterable
    {
        yield 'serde::Deserialize' => ['rust:interface:serde::Deserialize'];
        yield 'serde::de::Deserialize' => ['rust:interface:serde::de::Deserialize'];
        yield 'external' => ['rust:external_interface:serde::Deserialize'];
        yield 'bare' => ['rust:interface:Deserialize'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('deserializeImpls')]
    public function testARustStructWithAHandWrittenDeserializeHasNoList(string $impl): void
    {
        // A hand-written impl may read another shape than the struct's fields.
        $user = self::node('rust:class:crate::User', self::fields('id'));
        $graph = self::graph([$user], [['implements', $user, 'rust:interface:std::fmt::Display'], ['implements', $user, $impl]]);

        assertSame(null, FieldLists::of($graph, $user));
    }

    /** Only the struct's own impls are asked: a trait it implements may itself require `Deserialize` of an implementor. */
    public function testARustDeserializeIsReadOnTheStructAloneNotThroughItsTraits(): void
    {
        $wire = new NodeFact('rust:interface:crate::Wire', 'interface', 'Wire', 'crate::Wire', Origin::Ast, Confidence::Certain, new Evidence('src/a', 1, 9), []);
        $user = self::node('rust:class:crate::User', self::fields('id'));
        $graph = self::graph([$wire, $user], [
            ['implements', $user, 'rust:interface:crate::Wire'],
            ['extends', $wire, 'rust:interface:serde::Deserialize'],
        ]);

        assertSame(['id'], array_keys(FieldLists::of($graph, $user)?->fields() ?? []));
    }

    public function testAPythonModelComposesItsBasesAndStopsAtTheRootOutsideTheProject(): void
    {
        $base = self::node('py:class:app.Base', self::fields('id'));
        $child = self::node('py:class:app.User', self::fields('name'));
        $graph = self::graph([$base, $child], [
            ['extends', $base, 'py:external_symbol:pydantic.BaseModel'],
            ['extends', $child, 'py:class:app.Base'],
        ]);

        assertSame(['id', 'name'], array_keys(FieldLists::of($graph, $child)?->fields() ?? []));
    }

    public function testAPythonRootTheScanHoldsIsWalkedAsAnyAncestor(): void
    {
        // A project module named `pydantic` is the project's own class, without a list here.
        $root = self::node('py:class:pydantic.BaseModel', []);
        $child = self::node('py:class:app.User', self::fields('name'));
        $graph = self::graph([$root, $child], [['extends', $child, 'py:class:pydantic.BaseModel']]);

        assertSame(null, FieldLists::of($graph, $child));
    }

    public function testAPythonBaseOutsideTheProjectThatIsNoRootLeavesTheWholeUnknown(): void
    {
        $child = self::node('py:class:app.User', self::fields('name'));
        $graph = self::graph([$child], [['extends', $child, 'py:external_symbol:pydantic_settings.BaseSettings']]);

        assertSame(null, FieldLists::of($graph, $child));
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
