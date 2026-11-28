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

    /** @param list<array<string, mixed>> $impls the target's `trait_impls` entries */
    private function members(NodeFact $target, string $language, array $impls = []): MockMembers
    {
        return MockMembers::of(TestFidelityGraph::fromContributions([new ScanContribution('knossos.test:file:src/a', $this->nodes, $this->edges)]), $target, $language, impls: $impls);
    }

    /** A struct `$name` with an inherent `go`, implementing each of `$traits` that the scan holds. */
    private function struct(string $name, string ...$traits): NodeFact
    {
        $struct = $this->type('rust:class:crate::' . $name, 'class');
        $this->method($struct, 'go', ['signature' => ['params' => [], 'receiver' => '&self', 'returns' => 'bool']]);
        foreach ($traits as $trait) {
            $this->edge('implements', $struct, $this->type('rust:interface:crate::' . $trait, 'interface')->localId);
        }

        return $struct;
    }

    /**
     * @param list<MockMember> $members
     * @return list<array{string, string, ?string, bool}>
     */
    private static function shapes(array $members): array
    {
        return array_map(static fn(MockMember $member): array => [$member->name, $member->kind, $member->implTrait, $member->associated], $members);
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

    public function testAnEcmaScriptPrivateNameIsNoMember(): void
    {
        // `#rows = new Map()` (the TypeScript worker's own ReadTable): no double can reach it, so it is
        // neither a member nor an omission, and the list stays complete.
        $class = $this->type('ts:class:src/a.ts#ReadTable', 'class', ['member_names' => ['#rows', '#size', 'refer']]);
        $this->method($class, 'refer');

        $members = $this->members($class, 'typescript');

        assertSame(['refer'], self::names($members->members));
        assertSame([], $members->omitted);
        self::assertTrue($members->complete);
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
        // An auto supertrait needs no impl; Display's the template spells (G6-03); another one outside the scan does.
        $sendable = $this->type('rust:interface:crate::Sendable', 'interface');
        $this->method($sendable, 'go', ['signature' => ['params' => [], 'receiver' => '&self']]);
        $this->edge('extends', $sendable, 'rust:interface:crate::Send');
        $this->edge('extends', $sendable, 'rust:interface:std::marker::Sync');
        assertTrue($this->members($sendable, 'rust')->complete);
        assertSame([], $this->members($sendable, 'rust')->spelledSupertraits());
        $this->edge('extends', $sendable, 'rust:interface:std::fmt::Display');
        assertTrue($this->members($sendable, 'rust')->complete);
        assertSame(['Display'], $this->members($sendable, 'rust')->spelledSupertraits());
        $this->edge('extends', $sendable, 'rust:interface:std::fmt::Debug');
        assertSame(['Sendable has a supertrait outside the scan: mock! needs its impl too, and its methods are not listed.'], $this->members($sendable, 'rust')->notes);
        $empty = $this->type('rust:class:crate::Empty', 'class');
        assertSame(['Empty has no member a mock can stand in for.'], $this->members($empty, 'rust')->notes);
    }

    public function testARustSupertraitMethodOfATraitMethodsNameIsOmittedAndNamed(): void
    {
        // G6-14: `trait Store: Base` with `fn get` in both. mockall gives one expect_get per mock,
        // so the supertrait's is left out, named, and the list is not complete.
        $base = $this->type('rust:interface:crate::Base', 'interface');
        $this->method($base, 'get', ['signature' => ['params' => [], 'receiver' => '&self', 'returns' => 'u8']]);
        $this->method($base, 'id', ['signature' => ['params' => [], 'receiver' => '&self', 'returns' => 'u8']]);
        $store = $this->type('rust:interface:crate::Store', 'interface');
        $this->method($store, 'get', ['signature' => ['params' => [], 'receiver' => '&self', 'returns' => 'u16']]);
        $this->edge('extends', $store, $base->localId);

        $members = $this->members($store, 'rust');
        assertSame(['get', 'id'], self::names($members->members));
        assertSame([['name' => 'Base::get', 'reason' => 'Store declares get too: mockall cannot mock one method name from two traits']], $members->omitted);
        assertFalse($members->complete);
        // Without the clash the supertrait's methods are all listed and the list is complete.
        $other = $this->type('rust:interface:crate::Other', 'interface');
        $this->method($other, 'put', ['signature' => ['params' => [], 'receiver' => '&self']]);
        $this->edge('extends', $other, $base->localId);
        $clean = $this->members($other, 'rust');
        assertSame(['put', 'get', 'id'], self::names($clean->members));
        assertTrue($clean->complete);
    }

    public function testARustStructsTraitImplsKeepTheirTrait(): void
    {
        // `impl Repo<String> for Store`: each method names its impl, so the mock can give that impl.
        $trait = $this->type('rust:interface:crate::Repo', 'interface', ['type_params' => ['T']]);
        $this->method($trait, 'get', ['signature' => ['params' => [], 'receiver' => '&self', 'returns' => 'Option<T>']]);
        $store = $this->type('rust:class:crate::Store', 'class');
        $this->method($store, 'own', ['signature' => ['params' => [], 'receiver' => '&self']]);
        $this->method($store, '<impl Repo<String>>::get', ['overrides' => true, 'impl_trait' => 'Repo<String>', 'signature' => ['params' => [], 'receiver' => '&self', 'returns' => 'Option<String>']]);
        // A trait impl's method from a worker that does not say which trait: no faithful block.
        $this->method($store, '<impl Base>::base', ['overrides' => true, 'signature' => ['params' => [], 'receiver' => '&self']]);
        $this->edge('implements', $store, $trait->localId);

        $members = $this->members($store, 'rust');

        // The trait's own declaration is not walked into: the struct's impl says what it gives.
        assertSame(['own', '<impl Repo<String>>::get'], self::names($members->members));
        assertSame([null, 'Repo<String>'], array_map(static fn(MockMember $member): ?string => $member->implTrait, $members->members));
        assertSame([['name' => '<impl Base>::base', 'reason' => "a trait impl's method, whose trait the scan does not record: mock that trait instead"]], $members->omitted);
        assertFalse($members->complete);
    }

    public function testATraitImplsAssociatedItemsAreMembersOfItsBlock(): void
    {
        $producer = $this->type('rust:class:crate::Producer', 'class');
        $impl = ['overrides' => true, 'impl_trait' => 'WithAssoc', 'impl_types' => [['name' => 'Output', 'type' => 'u8']], 'impl_consts' => [['name' => 'LIMIT', 'type' => 'usize', 'value' => '3'], ['name' => 'NAME', 'type' => '&str']]];
        $this->method($producer, '<impl WithAssoc>::produce', [...$impl, 'signature' => ['params' => [], 'receiver' => '&self', 'returns' => 'u8']]);
        $this->method($producer, '<impl WithAssoc>::limit', [...$impl, 'signature' => ['params' => [], 'receiver' => '&self', 'returns' => 'usize']]);

        $members = $this->members($producer, 'rust');

        // Once per impl, before its methods, each in the impl's block.
        assertSame([['Output', 'type', 'u8', 'WithAssoc'], ['LIMIT', 'const', 'usize', 'WithAssoc'], ['NAME', 'const', '&str', 'WithAssoc'], ['<impl WithAssoc>::produce', 'method', null, 'WithAssoc'], ['<impl WithAssoc>::limit', 'method', null, 'WithAssoc']], array_map(static fn(MockMember $member): array => [$member->name, $member->kind, $member->type, $member->implTrait], $members->members));
        assertSame(['3', null], [$members->members[1]->signature['value'] ?? null, $members->members[2]->signature['value'] ?? null]);
        assertTrue($members->complete);
    }

    public function testOneMethodNameFromTwoImplsKeepsTheFirst(): void
    {
        // mockall makes one expect_into2() for a struct: a second impl of the name cannot be mocked beside it.
        $conv = $this->type('rust:class:crate::Conv2', 'class');
        $this->method($conv, '<impl Into2<Aa>>::into2', ['overrides' => true, 'impl_trait' => 'Into2<Aa>', 'signature' => ['params' => [], 'receiver' => '&self', 'returns' => 'Aa']]);
        $this->method($conv, '<impl Into2<Bb>>::into2', ['overrides' => true, 'impl_trait' => 'Into2<Bb>', 'signature' => ['params' => [], 'receiver' => '&self', 'returns' => 'Bb']]);
        $this->method($conv, 'into2', ['signature' => ['params' => [], 'receiver' => '&self']]);

        $members = $this->members($conv, 'rust');

        assertSame(['<impl Into2<Aa>>::into2'], self::names($members->members));
        assertSame([
            ['name' => '<impl Into2<Bb>>::into2', 'reason' => 'mockall cannot mock one method name from two impls'],
            ['name' => 'into2', 'reason' => 'mockall cannot mock one method name from two impls'],
        ], $members->omitted);
        assertFalse($members->complete);
    }

    public function testAStructsTraitOutsideTheScanIsSaidSo(): void
    {
        $engine = $this->type('rust:class:crate::Engine', 'class');
        $this->method($engine, 'start', ['signature' => ['params' => [], 'receiver' => '&self']]);
        $this->edge('implements', $engine, 'rust:interface:std::fmt::Display');

        assertSame(['Engine implements a trait outside the scan: its methods the impl does not write (defaults) are not listed.'], $this->members($engine, 'rust')->notes);
    }

    public function testMethodLessImplsAreBlocksOfTheMock(): void
    {
        // Mk3: `impl Tag for Mk3 {}` and `impl Marker for Mk3 { type Kind = u8; }`, both traits in the scan.
        $mk3 = $this->struct('Mk3', 'Tag', 'Marker');
        $members = $this->members($mk3, 'rust', [
            ['type' => 'crate::Mk3', 'trait' => 'Tag'],
            ['type' => 'crate::Mk3', 'trait' => 'Marker', 'types' => [['name' => 'Kind', 'type' => 'u8']]],
        ]);

        assertSame([['go', 'method', null, false], ['Kind', 'type', 'Marker', false]], self::shapes($members->members));
        assertSame([['trait' => 'Tag', 'unsafe' => false], ['trait' => 'Marker', 'unsafe' => false]], $members->impls);
        assertTrue($members->complete);
        assertSame([], $members->notes);
    }

    public function testATraitImplsAssociatedFunctionIsKept(): void
    {
        // Mf: `impl Make for Mf { fn make() -> Self }`, `impl Both for Mf { fn new_one() -> Self; fn id(&self) }`; an inherent `new` is the mock's own.
        $mf = $this->struct('Mf', 'Make', 'Both');
        $this->method($mf, 'new', ['signature' => ['params' => [], 'returns' => 'Self']]);
        $this->method($mf, 'make', ['overrides' => true, 'impl_trait' => 'Make', 'signature' => ['params' => [], 'returns' => 'Self']]);
        $this->method($mf, 'new_one', ['overrides' => true, 'impl_trait' => 'Both', 'signature' => ['params' => [], 'returns' => 'Self']]);
        $this->method($mf, 'id', ['overrides' => true, 'impl_trait' => 'Both', 'signature' => ['params' => [], 'receiver' => '&self', 'returns' => 'u32']]);
        $members = $this->members($mf, 'rust', [
            ['type' => 'crate::Mf', 'trait' => 'Make', 'methods' => ['make']],
            ['type' => 'crate::Mf', 'trait' => 'Both', 'methods' => ['new_one', 'id']],
        ]);

        assertSame([['go', 'method', null, false], ['make', 'method', 'Make', true], ['new_one', 'method', 'Both', true], ['id', 'method', 'Both', false]], self::shapes($members->members));
        assertTrue($members->complete);
    }

    public function testAnImplOfATraitOutsideTheScanIsIncompleteAndMarkersAreNot(): void
    {
        // Mk: in-scan method-less impls, an external Default, and the auto traits.
        $mk = $this->struct('Mk', 'Tag');
        $this->method($mk, 'default', ['overrides' => true, 'impl_trait' => 'Default', 'signature' => ['params' => [], 'returns' => 'Self']]);
        $this->edge('implements', $mk, 'rust:interface:crate::Default');
        $members = $this->members($mk, 'rust', [
            ['type' => 'crate::Mk', 'trait' => 'Tag'],
            ['type' => 'crate::Mk', 'trait' => 'Default', 'methods' => ['default']],
            ['type' => 'crate::Mk', 'trait' => 'Send', 'unsafe' => true],
            ['type' => 'crate::Mk', 'trait' => 'Sync', 'unsafe' => true],
        ]);
        assertSame(['Tag', 'Default'], array_column($members->impls, 'trait'));
        assertFalse($members->complete);
        assertSame(['Mk implements Default, a trait outside the scan: its methods the impl does not write (defaults) are not listed.'], $members->notes);

        // Mk2: marker traits only, which every mock has or needs no impl of.
        $mk2 = $this->struct('Mk2');
        $this->edge('implements', $mk2, 'rust:interface:crate::Send');
        $plain = $this->members($mk2, 'rust', [
            ['type' => 'crate::Mk2', 'trait' => 'Send', 'unsafe' => true],
            ['type' => 'crate::Mk2', 'trait' => 'Sync', 'unsafe' => true],
            ['type' => 'crate::Mk2', 'trait' => 'Unpin'],
        ]);
        assertSame([[], [], true], [$plain->impls, $plain->notes, $plain->complete]);

        // Mk4: Clone, Copy and Debug: Copy cannot be mocked, the others are outside the scan.
        $mk4 = $this->struct('Mk4');
        $this->method($mk4, 'clone', ['overrides' => true, 'impl_trait' => 'Clone', 'signature' => ['params' => [], 'receiver' => '&self', 'returns' => 'Self']]);
        $copy = $this->members($mk4, 'rust', [
            ['type' => 'crate::Mk4', 'trait' => 'Clone', 'methods' => ['clone']],
            ['type' => 'crate::Mk4', 'trait' => 'Copy'],
            ['type' => 'crate::Mk4', 'trait' => 'std::fmt::Debug', 'methods' => ['fmt']],
        ]);
        assertFalse($copy->complete);
        assertSame([
            'Mk4 implements Clone, a trait outside the scan: its methods the impl does not write (defaults) are not listed.',
            'Mk4 implements Copy: a mock cannot be Copy, so that impl is left out.',
            'Mk4 implements std::fmt::Debug, a trait outside the scan: its methods the impl does not write (defaults) are not listed.',
        ], $copy->notes);
        // An impl<T> cannot be written without its parameters.
        $gen = $this->struct('Gen');
        // G6-08g: the omitted impl's own method is omitted with it, never rendered in a block of its own.
        $this->method($gen, '<impl From<T>>::from', ['overrides' => true, 'impl_trait' => 'From<T>', 'signature' => ['params' => [['name' => 'value', 'type' => 'T']], 'returns' => 'Self']]);
        $generic = $this->members($gen, 'rust', [['type' => 'crate::Gen', 'trait' => 'From<T>', 'methods' => ['from'], 'generics' => true]]);
        assertSame([[], false], [$generic->impls, $generic->complete]);
        assertSame([['name' => 'impl From<T>', 'reason' => 'a generic impl (impl<T>): its parameters are not recorded']], $generic->omitted);
        assertSame(['go'], self::names($generic->members));
    }

    /** A marker trait or a generic impl is passed over, never the end of the list: the impls after it still count. */
    public function testImplsAfterAMarkerOrAGenericImplAreStillRead(): void
    {
        $mk = $this->struct('Mk5', 'Tag');
        $members = $this->members($mk, 'rust', [
            ['type' => 'crate::Mk5', 'trait' => 'Send', 'unsafe' => true],
            ['type' => 'crate::Mk5', 'trait' => 'From<T>', 'methods' => ['from'], 'generics' => true],
            ['type' => 'crate::Mk5', 'trait' => 'Tag'],
        ]);

        assertSame(['Tag'], array_column($members->impls, 'trait'));
        assertSame([['name' => 'impl From<T>', 'reason' => 'a generic impl (impl<T>): its parameters are not recorded']], $members->omitted);
    }

    public function testARustTraitsAssociatedItemsAreMembers(): void
    {
        $trait = $this->type('rust:interface:crate::WithAssoc', 'interface', ['associated_types' => ['Output', 'Item: Clone'], 'associated_consts' => [['name' => 'LIMIT', 'type' => 'usize']]]);
        $this->method($trait, 'produce', ['signature' => ['params' => [], 'receiver' => '&self', 'returns' => 'Self::Output']]);

        $members = $this->members($trait, 'rust');

        assertSame([['Output', 'type', 'Output'], ['Item', 'type', 'Item: Clone'], ['LIMIT', 'const', 'usize'], ['produce', 'method', null]], array_map(static fn(MockMember $member): array => [$member->name, $member->kind, $member->type], $members->members));
        assertTrue($members->complete);
    }

    public function testPhpMagicMethodsAreNoMembers(): void
    {
        $class = $this->type('php:class:App\Magic', 'class', ['member_names' => ['__call', '__get', '__toString', 'open']]);
        $this->method($class, '__call', ['visibility' => 'public', 'signature' => ['params' => []]]);
        $this->method($class, '__get', ['visibility' => 'public', 'signature' => ['params' => []]]);
        $this->method($class, '__toString', ['visibility' => 'public', 'signature' => ['params' => [], 'returns' => 'string']]);
        $this->method($class, 'open', ['visibility' => 'public', 'signature' => ['params' => []]]);

        assertSame(['open'], self::names($this->members($class, 'php')->members));
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
