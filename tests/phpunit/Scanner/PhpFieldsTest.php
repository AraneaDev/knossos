<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Scanner\Protocol\NodeFact;
use Knossos\TestFidelity\Facts\FieldLists;
use Knossos\TestFidelity\TestFidelityGraph;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\Support\ScanLimits;

use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * A PHP class, interface or trait node lists the fields it declares itself,
 * promoted constructor properties included, and an enum node its cases. A
 * serializer attribute or tag that may rename a key opens the list; the core
 * composes inherited fields along the scan's own `extends` and `uses_trait`
 * edges, so a parent outside the scan leaves the whole list unknown.
 */
#[Group('php-scanner')]
final class PhpFieldsTest extends KnossosTestCase
{
    public function testFieldsOfAClassWithNullableAndDefaultedMembers(): void
    {
        $order = $this->classLikes()['Fields\Order']->attributes;

        assertSame([
            ['name' => 'count', 'type' => 'int', 'required' => false, 'line' => 11],
            ['name' => 'id', 'type' => 'string', 'required' => true, 'line' => 9],
            ['name' => 'label', 'type' => '?string', 'required' => false, 'line' => 13],
            ['name' => 'loose', 'required' => false, 'line' => 12],
            ['name' => 'note', 'type' => '?string', 'required' => true, 'line' => 10],
            ['name' => 'other', 'type' => '?string', 'required' => true, 'line' => 13],
            ['name' => 'owner', 'type' => 'string', 'required' => true, 'line' => 16],
            ['name' => 'priority', 'type' => 'int', 'required' => false, 'line' => 17],
        ], $order['fields']);
        assertSame([null, null], [$order['fields_open'] ?? null, $order['fields_truncated'] ?? null]);
    }

    public function testClassFieldsWithInitializersAreOptional(): void
    {
        $fields = array_column($this->classLikes()['Fields\Order']->attributes['fields'], 'required', 'name');

        assertSame([false, false, true], [$fields['count'], $fields['priority'], $fields['id']]);
    }

    public function testCompoundTypesAreWrittenOut(): void
    {
        $types = array_column($this->classLikes()['Fields\Typed']->attributes['fields'], 'type', 'name');

        assertSame(['both' => 'Countable&Traversable', 'either' => '(Countable&Traversable)|null', 'scalar' => 'int|string'], $types);
    }

    public function testPromotedPropertiesAreFields(): void
    {
        $names = array_column($this->classLikes()['Fields\Order']->attributes['fields'], 'name');

        assertSame(true, in_array('owner', $names, true));
        assertSame(true, in_array('priority', $names, true));
        assertSame(false, in_array('notAProperty', $names, true));
        assertSame(false, in_array('table', $names, true));
    }

    public function testATraitAndAnInterfaceSendTheirOwnLists(): void
    {
        $nodes = $this->classLikes();

        assertSame([['name' => 'createdAt', 'type' => 'DateTimeImmutable', 'required' => true, 'line' => 35]], $nodes['Fields\Stamped']->attributes['fields']);
        assertSame([], $nodes['Fields\HasId']->attributes['fields']);
    }

    public function testAResolvedBaseMergesFields(): void
    {
        [$graph, $nodes] = $this->graph();

        $account = FieldLists::of($graph, $nodes['Fields\Account']);

        assertNotNull($account);
        assertSame(['createdAt', 'id', 'owner'], array_keys($account->fields()));
        // The worker sends the class's own fields; the inherited ones come from the graph.
        assertSame(['owner'], array_column($nodes['Fields\Account']->attributes['fields'], 'name'));
        assertSame('string', $account->fields()['id']['type']);
    }

    public function testAnUnresolvedBaseOpensTheList(): void
    {
        [$graph, $nodes] = $this->graph();

        assertSame(null, FieldLists::of($graph, $nodes['Fields\Imported']));
        assertSame(null, FieldLists::of($graph, $nodes['Fields\Grandchild']));
        assertSame(null, $nodes['Fields\Imported']->attributes['fields_open'] ?? null);
        assertNotNull(FieldLists::of($graph, $nodes['Fields\Order']));
    }

    public function testADecoratorOpensTheList(): void
    {
        $nodes = $this->classLikes();

        foreach (['SymfonyRenamed', 'SymfonyGrouped', 'SpatieMapped', 'JmsRenamed', 'JmsClassTag', 'AliasedAttribute', 'SymfonyIgnored', 'SymfonyDepth'] as $class) {
            assertSame([$class, true], [$class, $nodes['Fields\\' . $class]->attributes['fields_open'] ?? null]);
        }
        assertSame(null, $nodes['Fields\Unrelated']->attributes['fields_open'] ?? null);
        assertSame(null, $nodes['Fields\NotSerializerTags']->attributes['fields_open'] ?? null);
    }

    public function testMagicMembersLeaveTheListClosed(): void
    {
        $magic = $this->classLikes()['Fields\Magic']->attributes;

        assertSame([['name' => 'id', 'type' => 'string', 'required' => false, 'line' => 65]], $magic['fields']);
        assertSame(null, $magic['fields_open'] ?? null);
    }

    public function testBackedEnumCasesCarryValues(): void
    {
        $nodes = $this->classLikes();

        assertSame([['name' => 'Fast', 'value' => 'fast'], ['name' => 'Slow', 'value' => 'slow']], $nodes['Fields\Lane']->attributes['cases']);
        assertSame([['name' => 'Low', 'value' => '1'], ['name' => 'Minus', 'value' => '-2']], $nodes['Fields\Code']->attributes['cases']);
        assertSame(null, $nodes['Fields\Lane']->attributes['fields'] ?? null);
    }

    public function testPureEnumCasesHaveNoValues(): void
    {
        $nodes = $this->classLikes();

        assertSame([['name' => 'Hearts'], ['name' => 'Spades']], $nodes['Fields\Suit']->attributes['cases']);
        assertSame([['name' => 'A'], ['name' => 'B']], $nodes['Fields\Computed']->attributes['cases']);
    }

    public function testA64FieldClassIsCutAndMarked(): void
    {
        $nodes = $this->classLikes();
        $wide = $nodes['Fields\Wide']->attributes;

        assertSame(64, count($wide['fields']));
        assertSame('f63', $wide['fields'][63]['name']);
        assertSame(true, $wide['fields_truncated']);
        assertSame(64, count($nodes['Fields\WideEnum']->attributes['cases']));
        assertSame(true, $nodes['Fields\WideEnum']->attributes['cases_truncated']);
    }

    /**
     * Every class-like node of the fixture, by canonical name.
     *
     * @return array<string, NodeFact>
     */
    private function classLikes(): array
    {
        return $this->graph()[1];
    }

    /**
     * The test-fidelity graph over the whole fixture, and its class-like nodes by canonical name.
     *
     * @return array{TestFidelityGraph, array<string, NodeFact>}
     */
    private function graph(): array
    {
        $client = $this->phpWorkerClient();
        try {
            $contributions = iterator_to_array($client->scan(['limits' => ScanLimits::DEFAULT,
                'root' => self::repositoryRoot() . '/tests/Fixtures/test-fidelity/php/fields',
                'files' => ['src/Dtos.php', 'src/Enums.php', 'src/Lineage.php', 'src/Serialized.php', 'src/Wide.php'],
            ]), false);
        } finally {
            $client->shutdown();
        }
        $nodes = [];
        foreach ($contributions as $contribution) {
            foreach ($contribution->nodes as $node) {
                if (in_array($node->kind, ['class', 'interface', 'trait', 'enum'], true)) {
                    $nodes[$node->canonicalName] = $node;
                }
            }
        }

        return [TestFidelityGraph::fromContributions($contributions), $nodes];
    }
}
