<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity\Synthesis;

use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\EdgeFact;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\TestFidelity\Synthesis\MockMember;
use Knossos\TestFidelity\Synthesis\MockMembers;
use Knossos\TestFidelity\TestFidelityGraph;
use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * The members a mock renders (Decision 5): public instance methods, declared
 * and inherited nearest first; what cannot be rendered faithfully under
 * `omitted`; and `complete` only when nothing was left out and nothing may
 * come from elsewhere.
 */
final class MockMembersTest extends KnossosTestCase
{
    /** @var list<NodeFact> */
    private array $nodes = [];

    /** @var list<EdgeFact> */
    private array $edges = [];

    private int $line = 1;

    /** @param array<string, mixed> $attributes */
    private function type(string $localId, string $kind, array $attributes = []): NodeFact
    {
        $name = (string) preg_replace('/^.*[#:.\\\\]/', '', $localId);
        $node = new NodeFact($localId, $kind, substr($localId, (int) strpos($localId, ':', (int) strpos($localId, ':') + 1) + 1), $name, Origin::Ast, Confidence::Certain, new Evidence('src/a', $this->line, $this->line), $attributes);
        $this->nodes[] = $node;
        $this->line++;

        return $node;
    }

    /** @param array<string, mixed> $attributes */
    private function method(NodeFact $owner, string $name, array $attributes = ['signature' => ['params' => []]], string $kind = 'method'): void
    {
        $prefix = substr($owner->localId, 0, (int) strpos($owner->localId, ':'));
        $node = new NodeFact($prefix . ':' . $kind . ':' . $owner->canonicalName . '::' . $name, $kind, $owner->canonicalName . '::' . $name, $name, Origin::Ast, Confidence::Certain, new Evidence('src/a', $this->line, $this->line), $attributes);
        $this->line++;
        $this->nodes[] = $node;
        $this->edge('contains', $owner, $node->localId);
    }

    private function edge(string $kind, NodeFact $source, string $target): void
    {
        $this->edges[] = new EdgeFact($kind, $source->localId, $target, Origin::Ast, Confidence::Certain, $source->evidence);
    }

    private function members(NodeFact $target, string $language): MockMembers
    {
        return MockMembers::of(TestFidelityGraph::fromContributions([new ScanContribution('knossos.test:file:src/a', $this->nodes, $this->edges)]), $target, $language);
    }

    /**
     * @param list<MockMember> $members
     * @return list<string>
     */
    private static function names(array $members): array
    {
        return array_map(static fn(MockMember $member): string => $member->name, $members);
    }

    public function testInheritedMembersComeAfterTheOwnNearestFirst(): void
    {
        $base = $this->type('ts:class:src/a.ts#Base', 'class', ['member_names' => ['load', 'save']]);
        $this->method($base, 'save');
        $this->method($base, 'load');
        $child = $this->type('ts:class:src/a.ts#Child', 'class', ['member_names' => ['load', 'own', 'save']]);
        $this->method($child, 'own');
        $this->method($child, 'save', ['signature' => ['params' => [['name' => 'x', 'type' => 'string']]]]);
        $this->edge('extends', $child, $base->localId);

        $members = $this->members($child, 'typescript');

        assertSame(['own', 'save', 'load'], self::names($members->members));
        // The nearest declaration wins: the child's own `save`.
        assertSame($child->localId, $members->members[1]->declarer->localId);
        assertSame([], $members->omitted);
        assertTrue($members->complete);
        assertSame('3 members; complete', $members->summary());
    }

    public function testPrivateStaticAndConstructorsAreLeftOut(): void
    {
        $class = $this->type('ts:class:src/a.ts#Svc', 'class', ['member_names' => ['constructor', 'guarded', 'hidden', 'make', 'shown']]);
        $this->method($class, 'constructor');
        $this->method($class, 'hidden', ['visibility' => 'private', 'signature' => ['params' => []]]);
        $this->method($class, 'guarded', ['visibility' => 'protected', 'signature' => ['params' => []]]);
        $this->method($class, 'make', ['static' => true, 'signature' => ['params' => []]]);
        $this->method($class, 'shown');
        $php = $this->type('php:class:App\Svc', 'class', ['member_names' => ['__construct', 'make', 'open', 'prot']]);
        $this->method($php, '__construct', ['visibility' => 'public', 'signature' => ['params' => []]]);
        $this->method($php, 'make', ['visibility' => 'public', 'static' => true, 'signature' => ['params' => []]]);
        $this->method($php, 'prot', ['visibility' => 'protected', 'signature' => ['params' => []]]);
        $this->method($php, 'open', ['visibility' => 'public', 'signature' => ['params' => []]]);

        assertSame(['shown'], self::names($this->members($class, 'typescript')->members));
        assertTrue($this->members($class, 'typescript')->complete);
        assertSame(['open'], self::names($this->members($php, 'php')->members));
    }

    public function testTruncatedOverloadedAndDecoratedSignaturesAreOmitted(): void
    {
        $class = $this->type('py:class:app.Store', 'class', ['member_names' => ['cut', 'deco', 'over', 'plain', 'unread']]);
        $this->method($class, 'cut', ['signature' => ['params' => [], 'truncated' => true]]);
        $this->method($class, 'over', ['overloaded' => true, 'decorated' => true, 'signature' => ['params' => []]]);
        $this->method($class, 'deco', ['decorated' => true, 'signature' => ['params' => []]]);
        $this->method($class, 'unread', []);
        $this->method($class, 'plain');

        $members = $this->members($class, 'python');

        assertSame(['plain'], self::names($members->members));
        assertSame([
            ['name' => 'cut', 'reason' => 'its signature was cut (more than 12 parameters, or a type over 80 characters)'],
            ['name' => 'over', 'reason' => 'overloaded: one recorded signature cannot stand for every overload'],
            ['name' => 'deco', 'reason' => 'decorated: a decorator or a rebinding may change its signature'],
            ['name' => 'unread', 'reason' => 'no signature recorded'],
        ], $members->omitted);
        assertFalse($members->complete);
        assertSame('1 member, 4 omitted; incomplete: see omitted and notes', $members->summary());
    }

    public function testANameWithoutANodeIsOmitted(): void
    {
        // `get label()` is in member_names but has no node; `value` is a field, `hidden` a private method.
        $class = $this->type('ts:class:src/a.ts#Svc', 'class', ['member_names' => ['hidden', 'label', 'total', 'value'], 'fields' => [['name' => 'value', 'type' => 'number', 'required' => true, 'line' => 2]]]);
        $this->method($class, 'total');
        $this->method($class, 'hidden', ['visibility' => 'private', 'signature' => ['params' => []]]);

        $members = $this->members($class, 'typescript');

        assertSame(['total'], self::names($members->members));
        assertSame([['name' => 'label', 'reason' => 'no signature recorded: an accessor, a docblock @method, or a member the scan gives no node']], $members->omitted);
    }

    public function testAnOpenListIsIncomplete(): void
    {
        $open = $this->type('ts:class:src/a.ts#Open', 'class', ['member_names' => ['a'], 'member_names_open' => true]);
        $this->method($open, 'a');
        $unlisted = $this->type('ts:class:src/a.ts#Unlisted', 'class');
        $this->method($unlisted, 'a');
        $orphan = $this->type('ts:class:src/a.ts#Orphan', 'class', ['member_names' => ['a']]);
        $this->method($orphan, 'a');
        $this->edge('extends', $orphan, 'ts:external_class:Remote');
        $rewritten = $this->type('py:class:app.Meta', 'class', ['member_names' => ['a'], 'methods_rewritable' => true]);
        $this->method($rewritten, 'a');
        $magic = $this->type('php:class:App\Magic', 'class', ['member_names' => ['__call', 'a']]);
        $this->method($magic, 'a', ['visibility' => 'public', 'signature' => ['params' => []]]);
        $this->method($magic, '__call', ['visibility' => 'public', 'signature' => ['params' => []]]);

        foreach ([[$open, 'typescript'], [$unlisted, 'typescript'], [$orphan, 'typescript'], [$rewritten, 'python'], [$magic, 'php']] as [$type, $language]) {
            $members = $this->members($type, $language);
            assertFalse($members->complete, $type->displayName);
            assertSame(1, count($members->notes), $type->displayName);
        }
        assertSame(['Orphan inherits from a type outside the scan, whose members are not listed.'], $this->members($orphan, 'typescript')->notes);
        assertSame(['Magic answers any method name through __call.'], $this->members($magic, 'php')->notes);
    }

    public function testAPythonUnderscoreMemberIsKeptWithANote(): void
    {
        $class = $this->type('py:class:app.Store', 'class', ['member_names' => ['_Store__secret', '__secret', '_refresh', 'get']]);
        $this->method($class, '__init__');
        $this->method($class, '__len__');
        $this->method($class, '__secret', ['visibility' => 'private', 'signature' => ['params' => []]]);
        $this->method($class, '_refresh', ['visibility' => 'protected', 'signature' => ['params' => []]]);
        $this->method($class, 'get');
        $this->method($class, 'build', ['decorators' => ['staticmethod'], 'signature' => ['params' => []]]);
        $this->method($class, 'create', ['decorators' => ['classmethod'], 'signature' => ['params' => []]]);

        $members = $this->members($class, 'python');

        assertSame(['_refresh', 'get'], self::names($members->members));
        assertSame(['_refresh is protected (a leading underscore): it is kept, as create_autospec allows it, though a test should rarely need it.'], $members->notes);
        assertTrue($members->complete);
    }

    public function testRustVisibilityAndAssociatedFunctions(): void
    {
        $trait = $this->type('rust:interface:crate::Repo', 'interface');
        $this->method($trait, 'find', ['signature' => ['params' => [], 'receiver' => '&self']]);
        $this->method($trait, 'open', ['signature' => ['params' => []]]);
        $struct = $this->type('rust:class:crate::Engine', 'class');
        $this->method($struct, 'new', ['signature' => ['params' => []]]);
        $this->method($struct, 'start', ['signature' => ['params' => [], 'receiver' => '&mut self']]);
        $this->method($struct, 'tune', ['visibility' => 'pub(crate)', 'signature' => ['params' => [], 'receiver' => '&self']]);
        $this->method($struct, 'hidden', ['visibility' => 'private', 'signature' => ['params' => [], 'receiver' => '&self']]);

        $members = $this->members($trait, 'rust');

        // A trait's associated function stays, since an impl must give it; a struct's is left out.
        assertSame(['find', 'open'], self::names($members->members));
        assertSame([false, true], array_map(static fn(MockMember $member): bool => $member->associated, $members->members));
        // A restricted visibility is kept, with a note: the crate's own tests call it.
        $engine = $this->members($struct, 'rust');
        assertSame(['start', 'tune'], self::names($engine->members));
        assertSame(["tune is pub(crate): it is kept, as the crate's own tests call it; a mock outside the crate cannot."], $engine->notes);
        // An auto supertrait needs no impl; another one outside the scan does.
        $sendable = $this->type('rust:interface:crate::Sendable', 'interface');
        $this->method($sendable, 'go', ['signature' => ['params' => [], 'receiver' => '&self']]);
        $this->edge('extends', $sendable, 'rust:interface:crate::Send');
        $this->edge('extends', $sendable, 'rust:interface:std::marker::Sync');
        assertTrue($this->members($sendable, 'rust')->complete);
        $this->edge('extends', $sendable, 'rust:interface:std::fmt::Display');
        assertSame(['Sendable has a supertrait outside the scan: mock! needs its impl too, and its methods are not listed.'], $this->members($sendable, 'rust')->notes);
        $empty = $this->type('rust:class:crate::Empty', 'class');
        assertSame(['Empty has no member a mock can stand in for.'], $this->members($empty, 'rust')->notes);
    }

    public function testAnInheritedGenericMemberIsOmittedAndAnOwnOneKept(): void
    {
        // `NumGen extends Gen<number>`: the scan does not record the argument, so `get(): T` is not NumGen's shape.
        $gen = $this->type('ts:class:src/a.ts#Gen', 'class', ['type_params' => ['T'], 'member_names' => ['get', 'name']]);
        $this->method($gen, 'get', ['signature' => ['params' => [], 'returns' => 'T']]);
        $this->method($gen, 'name', ['signature' => ['params' => [], 'returns' => 'string']]);
        $this->method($gen, 'map', ['signature' => ['params' => [['name' => 'x', 'type' => 'U']], 'returns' => 'U', 'type_params' => ['T']]]);
        $num = $this->type('ts:class:src/a.ts#NumGen', 'class', ['member_names' => ['get', 'map', 'name']]);
        $this->edge('extends', $num, $gen->localId);

        $members = $this->members($num, 'typescript');

        assertSame(['name'], self::names($members->members));
        assertSame([
            ['name' => 'get', 'reason' => 'uses T, a type parameter of Gen, whose argument for NumGen the scan does not record'],
            // A generic method is no stub's shape in TypeScript, on the declaring class too.
            ['name' => 'map', 'reason' => "generic: a vitest or jest mock's type cannot keep its own type parameters, so no stub fits it; write it as vi.fn() as unknown as NumGen['map']"],
        ], $members->omitted);
        assertFalse($members->complete);
        assertSame(['get', 'name'], self::names($this->members($gen, 'typescript')->members));
        assertSame(['T'], $this->members($gen, 'typescript')->typeParams());
    }

    public function testAnInterfacesPropertiesTakeTheirFieldTypes(): void
    {
        $widget = $this->type('ts:interface:src/a.ts#Widget', 'interface', ['member_names' => ['id', 'label', 'render'], 'fields' => [
            ['name' => 'id', 'type' => 'number', 'required' => true, 'line' => 1],
            ['name' => 'label', 'type' => 'string', 'required' => false, 'line' => 1],
        ]]);
        $this->method($widget, 'id', ['static' => false], 'property');
        $this->method($widget, 'label', ['static' => false], 'property');
        $this->method($widget, 'render', ['signature' => ['params' => [['name' => 'opts', 'type' => 'string']], 'returns' => 'void']]);
        $class = $this->type('ts:class:src/a.ts#Data', 'class', ['member_names' => ['id']]);
        $this->method($class, 'id', [], 'property');

        $members = $this->members($widget, 'typescript');

        assertSame(['id', 'label', 'render'], self::names($members->members));
        assertSame([['property', 'number', false], ['property', 'string', true], ['method', null, false]], array_map(static fn(MockMember $member): array => [$member->kind, $member->type, $member->optional], $members->members));
        // A class's data properties are no part of its mock.
        assertSame([], self::names($this->members($class, 'typescript')->members));
    }
}
