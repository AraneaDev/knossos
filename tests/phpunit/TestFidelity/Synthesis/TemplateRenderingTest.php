<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity\Synthesis;

use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\EdgeFact;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\TestFidelity\Synthesis\MockMembers;
use Knossos\TestFidelity\Synthesis\PhpTemplate;
use Knossos\TestFidelity\Synthesis\RustTemplate;
use Knossos\TestFidelity\TestFidelityGraph;
use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;

use PHPUnit\Framework\Attributes\Group;

/**
 * Two template details of G6-08g: the Rust template renders each member
 * once (a member's example value asks the graph for the types it names, and
 * the template's imports and its expectations come from the same
 * rendering), and a PHP parameter taken by reference keeps its `&` in the
 * member's comment line.
 */
#[Group('test-fidelity')]
final class TemplateRenderingTest extends KnossosTestCase
{
    public function testEachRustMemberIsRenderedOnce(): void
    {
        $members = self::members('rust', 'rust:interface:crate::Shop', 'interface', 'crate::Shop', ['order' => ['params' => [], 'receiver' => '&self', 'returns' => 'Order'], 'refund' => ['params' => [], 'receiver' => '&self', 'returns' => 'Order']]);
        $asked = [];
        $template = new RustTemplate(static function (string $name, string $file) use (&$asked): null {
            $asked[] = $name;

            return null;
        });

        $text = $template->render($members, 'mockall', true);
        assertStringContainsString('mock.expect_order()', $text);
        assertStringContainsString('mock.expect_refund()', $text);
        // One question per member, not one for its imports and another for its expectation.
        assertSame(count($members->members), count($asked));
    }

    public function testAMethodReturningNeverLeavesTheMockallMockIncomplete(): void
    {
        // mockall's expansion of `-> !` needs the never type, stable only from Rust 1.100 (E0658 before).
        $signatures = ['label' => ['params' => [], 'receiver' => '&self', 'returns' => 'String'], 'never' => ['params' => [], 'receiver' => '&self', 'returns' => '!']];
        $trait = self::members('rust', 'rust:interface:crate::Widget', 'interface', 'crate::Widget', $signatures);
        $template = new RustTemplate(static fn(string $name, string $file): null => null);

        $text = $template->render($trait, 'mockall', true);
        assertSame('// 2 members; incomplete: see omitted and notes', explode("\n", $text)[1]);
        assertSame(['never: mockall cannot mock a method returning ! (the never type is unstable before Rust 1.100) in an impl of Widget; this mock does not compile until it is removed, so fake the trait by hand (framework hand-written)'], $template->member($trait, $trait->members[1], 'mockall', true)['unknown']);
        assertSame([], $template->member($trait, $trait->members[0], 'mockall', true)['unknown']);
        // A struct's inherent `-> !` fails in mock! the same way; it has no trait to fake.
        $struct = self::members('rust', 'rust:class:crate::Gadget', 'class', 'crate::Gadget', $signatures);
        assertSame('// 2 members; incomplete: see omitted and notes', explode("\n", $template->render($struct, 'mockall', true))[1]);
        assertSame(['never: mockall cannot mock a method returning ! (the never type is unstable before Rust 1.100) in Gadget; this mock does not compile until it is removed'], $template->member($struct, $struct->members[1], 'mockall', true)['unknown']);
        // A hand-written fake's body diverges on stable Rust, so the fake is complete.
        $fake = $template->render($trait, 'hand-written', true);
        assertSame('// 2 members; complete', explode("\n", $fake)[1]);
        assertStringContainsString("    fn never(&self) -> ! {\n        panic!()\n    }", $fake);
    }

    public function testAPhpParameterByReferenceKeepsItsAmpersand(): void
    {
        $members = self::members('php', 'php:class:App\\Swap', 'class', 'App\\Swap', ['swap' => ['params' => [['name' => 'left', 'type' => 'int', 'by_ref' => true], ['name' => 'rest', 'type' => 'int', 'by_ref' => true, 'variadic' => true], ['name' => 'plain', 'type' => 'int']], 'returns' => 'void']]);
        $template = new PhpTemplate(static fn(string $name, string $file): null => null);

        assertSame('// swap(int &$left, int &...$rest, int $plain): void', explode("\n", $template->member($members, $members->members[0], 'phpunit', true)['rendered'])[0]);
    }

    /**
     * The members of one type `$localId` declaring each of `$methods`, a name to its signature.
     *
     * @param array<string, array<string, mixed>> $methods
     */
    private static function members(string $language, string $localId, string $kind, string $canonical, array $methods): MockMembers
    {
        $type = new NodeFact($localId, $kind, $canonical, (string) preg_replace('/^.*(?:::|\\\\)/', '', $canonical), Origin::Ast, Confidence::Certain, new Evidence('src/shop', 1, 9));
        $nodes = [$type];
        $edges = [];
        $line = 2;
        foreach ($methods as $name => $signature) {
            $method = new NodeFact($language . ':method:' . $canonical . '::' . $name, 'method', $canonical . '::' . $name, $name, Origin::Ast, Confidence::Certain, new Evidence('src/shop', $line, $line), ['signature' => $signature]);
            $nodes[] = $method;
            $edges[] = new EdgeFact('contains', $type->localId, $method->localId, Origin::Ast, Confidence::Certain, $type->evidence);
            ++$line;
        }

        return MockMembers::of(TestFidelityGraph::fromContributions([new ScanContribution('knossos.test:file:src/shop', $nodes, $edges)]), $type, $language);
    }
}
