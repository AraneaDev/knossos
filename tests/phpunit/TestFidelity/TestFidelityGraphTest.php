<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity;

use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\EdgeFact;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\TestFidelity\TestFidelityGraph;
use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

use PHPUnit\Framework\Attributes\Group;

#[Group('test-fidelity')]
final class TestFidelityGraphTest extends KnossosTestCase
{
    /** @param array<string, mixed> $attributes */
    private static function node(string $kind, string $canonical, string $display, array $attributes = []): NodeFact
    {
        $file = explode('#', $canonical)[0];

        return new NodeFact('ts:' . $kind . ':' . $canonical, $kind, $canonical, $display, Origin::Ast, Confidence::Certain, new Evidence($file, 1, 1), $attributes);
    }

    private static function edge(string $kind, NodeFact $source, string $target): EdgeFact
    {
        return new EdgeFact($kind, $source->localId, $target, Origin::Ast, Confidence::Certain, $source->evidence);
    }

    /** @param array<string, string> $target */
    private static function double(string $canonical, array $target): NodeFact
    {
        return self::node('test_double', $canonical, 'vi.spyOn', [
            'framework' => 'vitest', 'pattern' => 'vi.spyOn', 'target' => $target, 'automock' => false, 'invocations' => 0,
            'members' => [['name' => 'getProfil', 'line' => 6, 'api' => 'spyOn', 'returns' => []]],
        ]);
    }

    /** @return list<ScanContribution> */
    private static function contributions(): array
    {
        $module = self::node('module', 'src/UserService.ts', 'UserService.ts');
        $class = self::node('class', 'src/UserService.ts#UserService', 'UserService');
        $method = self::node('method', 'src/UserService.ts#UserService::getProfile', 'getProfile');
        $spy = self::double('tests/a.test.ts#double:6:3', ['kind' => 'instance-member', 'export' => 'UserService', 'member' => 'getProfil']);
        $package = self::double('tests/a.test.ts#double:2:1', ['kind' => 'module', 'specifier' => 'axios']);
        $alias = self::double('tests/a.test.ts#double:3:1', ['kind' => 'module', 'specifier' => '@/gone']);
        $relative = self::double('tests/a.test.ts#double:4:1', ['kind' => 'module', 'specifier' => './gone']);
        $vitest = self::node('package', 'vitest', 'vitest', ['external' => true]);
        $mocked = self::double('tests/a.test.ts#double:5:1', ['kind' => 'module', 'specifier' => 'vitest']);
        $case = self::node('test_case', 'tests/a.test.ts#test:4', 'a test', [
            'name' => 'a test', 'production_calls' => 1, 'subjects' => ['src/UserService.ts'], 'assertions' => [],
        ]);
        $broken = self::node('test_double', 'tests/a.test.ts#double:9:9', 'broken', ['framework' => 'mocha']);

        return [
            new ScanContribution('knossos.typescript:file:src/UserService.ts', [$module, $class, $method], [
                self::edge('contains', $module, $class->localId),
                self::edge('contains', $class, $method->localId),
            ]),
            new ScanContribution('knossos.typescript:file:tests/a.test.ts', [$spy, $package, $alias, $relative, $vitest, $mocked, $case, $broken], [
                self::edge('doubles', $spy, $class->localId),
                self::edge('doubles', $mocked, $vitest->localId),
            ]),
        ];
    }

    public function testReadsDoublesTestCasesTargetsAndMembers(): void
    {
        $graph = TestFidelityGraph::fromContributions(self::contributions());

        // Sorted by canonical name; the malformed double is left out, never a throw.
        assertSame(
            ['tests/a.test.ts#double:2:1', 'tests/a.test.ts#double:3:1', 'tests/a.test.ts#double:4:1', 'tests/a.test.ts#double:5:1', 'tests/a.test.ts#double:6:3'],
            array_map(static fn($double): string => $double->canonicalName, $graph->doubles()),
        );
        assertSame(['tests/a.test.ts#test:4'], array_map(static fn($case): string => $case->canonicalName, $graph->testCases()));

        $spy = $graph->doubles()[4];
        assertSame('ts:test_double:tests/a.test.ts#double:6:3', $graph->nodeOf($spy)->localId);
        $target = $graph->target($spy);
        assertNotNull($target);
        assertSame('src/UserService.ts#UserService', $target->canonicalName);
        assertSame('src/UserService.ts#UserService::getProfile', $graph->member($target, 'getProfile')?->canonicalName);
        assertNull($graph->member($target, 'getProfil'));
        assertNull($graph->target($graph->doubles()[0]));
        assertSame('tests/a.test.ts#test:4', $graph->nodeOf($graph->testCases()[0])->canonicalName);
    }

    public function testATargetOutsideTheProjectIsExternal(): void
    {
        [$package, $alias, $relative, $mocked, $spy] = ($graph = TestFidelityGraph::fromContributions(self::contributions()))->doubles();

        assertTrue($graph->isExternal($package), 'a bare npm specifier names a package');
        assertTrue($graph->isExternal($mocked), 'a target node marked external');
        assertFalse($graph->isExternal($alias), 'a path alias is inside the project');
        assertFalse($graph->isExternal($relative), 'a relative specifier is inside the project');
        assertFalse($graph->isExternal($spy), 'a resolved production class');
    }

    /**
     * `Child extends Base implements Named`, `Base extends Root`, and
     * `Root extends Child`: a cycle a malformed contribution can carry.
     *
     * @return array{0: TestFidelityGraph, 1: NodeFact}
     */
    private static function hierarchy(): array
    {
        $types = [];
        foreach (['Child' => 'class', 'Base' => 'class', 'Root' => 'class', 'Named' => 'interface'] as $name => $kind) {
            $types[$name] = self::node($kind, 'src/a.ts#' . $name, $name);
        }
        $members = [
            self::node('method', 'src/a.ts#Child::own', 'own'),
            self::node('method', 'src/a.ts#Base::greet', 'greet'),
            self::node('method', 'src/a.ts#Root::greet', 'greet'),
            self::node('method', 'src/a.ts#Root::deep', 'deep'),
            self::node('method', 'src/a.ts#Named::name', 'name'),
        ];
        $owner = static fn(NodeFact $member): NodeFact => $types[explode('::', explode('#', $member->canonicalName)[1])[0]];
        $edges = [
            self::edge('extends', $types['Child'], $types['Base']->localId),
            self::edge('implements', $types['Child'], $types['Named']->localId),
            self::edge('extends', $types['Base'], $types['Root']->localId),
            self::edge('extends', $types['Root'], $types['Child']->localId),
        ];
        foreach ($members as $member) {
            $edges[] = self::edge('contains', $owner($member), $member->localId);
        }

        return [TestFidelityGraph::fromContributions([new ScanContribution('knossos.typescript:file:src/a.ts', [...array_values($types), ...$members], $edges)]), $types['Child']];
    }

    public function testAnInheritedMemberResolvesToTheNearestDeclaration(): void
    {
        [$graph, $child] = self::hierarchy();

        assertSame('src/a.ts#Child::own', $graph->member($child, 'own')?->canonicalName);
        assertSame('src/a.ts#Base::greet', $graph->member($child, 'greet')?->canonicalName);
        assertSame('src/a.ts#Root::deep', $graph->member($child, 'deep')?->canonicalName);
    }

    public function testAMemberOfAnImplementedInterfaceResolves(): void
    {
        [$graph, $child] = self::hierarchy();

        assertSame('src/a.ts#Named::name', $graph->member($child, 'name')?->canonicalName);
    }

    public function testACyclicHierarchyTerminates(): void
    {
        [$graph, $child] = self::hierarchy();

        assertNull($graph->member($child, 'absent'));
    }

    public function testHasTargetIsTrueForAnEdgeWhateverItPointsAt(): void
    {
        $double = self::double('tests/a.test.ts#double:7:1', ['kind' => 'module', 'specifier' => './x']);
        $graph = TestFidelityGraph::fromContributions([new ScanContribution('knossos.typescript:file:tests/a.test.ts', [$double], [self::edge('doubles', $double, 'ts:module:src/x.ts')])]);

        assertTrue($graph->hasTarget($graph->doubles()[0]));
        assertNull($graph->target($graph->doubles()[0]));
        assertFalse(TestFidelityGraph::fromContributions(self::contributions())->hasTarget(TestFidelityGraph::fromContributions(self::contributions())->doubles()[2]));
    }

    public function testMemberNamesCoverTheWholeHierarchySorted(): void
    {
        [$graph, $child] = self::hierarchy();

        assertSame(['deep', 'greet', 'name', 'own'], $graph->memberNames($child));
        assertFalse($graph->hasUnseenAncestor($child));
    }

    public function testAnAncestorOutsideTheScanIsUnseen(): void
    {
        $class = self::node('class', 'src/a.ts#A', 'A');
        $graph = TestFidelityGraph::fromContributions([new ScanContribution('knossos.typescript:file:src/a.ts', [$class], [self::edge('extends', $class, 'ts:class:lib#Base')])]);

        assertTrue($graph->hasUnseenAncestor($class));
    }

    public function testAModuleReExportingAnotherResolvesItsMembers(): void
    {
        $facade = self::node('module', 'src/index.ts', 'index.ts');
        $inner = self::node('module', 'src/inner.ts', 'inner.ts');
        $fn = self::node('function', 'src/inner.ts#helper', 'helper');
        $graph = TestFidelityGraph::fromContributions([new ScanContribution('knossos.typescript:file:src', [$facade, $inner, $fn], [
            self::edge('re_exports', $facade, $inner->localId),
            self::edge('contains', $inner, $fn->localId),
        ])]);

        assertSame('src/inner.ts#helper', $graph->member($facade, 'helper')?->canonicalName);
    }
    /**
     * PHP doubles as the PHP worker emits them: every `doubles` edge names
     * `php:class:<FQCN>`, whatever the target's kind, in the case the test wrote.
     *
     * @return array{TestFidelityGraph, NodeFact}
     */
    private static function phpGraph(string ...$references): array
    {
        $file = 'src/Contracts/PaymentGateway.php';
        $gateway = new NodeFact('php:interface:App\\Contracts\\PaymentGateway', 'interface', 'App\\Contracts\\PaymentGateway', 'PaymentGateway', Origin::Ast, Confidence::Certain, new Evidence($file, 5, 10), ['member_names' => ['chargeToken']]);
        $charge = new NodeFact('php:method:App\\Contracts\\PaymentGateway::chargeToken', 'method', 'App\\Contracts\\PaymentGateway::chargeToken', 'chargeToken', Origin::Ast, Confidence::Certain, new Evidence($file, 7, 7), []);
        $vendored = new NodeFact('php:class:Monolog\\Logger', 'class', 'Monolog\\Logger', 'Logger', Origin::Ast, Confidence::Certain, new Evidence('vendor/monolog/monolog/src/Logger.php', 1, 1), []);
        $doubles = $edges = [];
        foreach ($references as $i => $reference) {
            $doubles[] = $double = new NodeFact('php:test_double:tests/PayTest.php#double:' . ($i + 1) . ':1', 'test_double', 'tests/PayTest.php#double:' . ($i + 1) . ':1', 'createMock', Origin::Ast, Confidence::Certain, new Evidence('tests/PayTest.php', $i + 1, $i + 1), [
                'framework' => 'phpunit', 'pattern' => 'createMock', 'target' => ['kind' => 'class', 'export' => substr($reference, strlen('php:class:'))], 'automock' => false, 'invocations' => 0, 'members' => [],
            ]);
            $edges[] = self::edge('doubles', $double, $reference);
        }

        return [TestFidelityGraph::fromContributions([
            new ScanContribution('knossos.php:file:src', [$gateway, $charge, $vendored], [self::edge('contains', $gateway, $charge->localId)]),
            new ScanContribution('knossos.php:file:tests/PayTest.php', $doubles, $edges),
        ]), $gateway];
    }

    public function testAPhpClassReferenceResolvesToTheTypeOfThatName(): void
    {
        [$graph, $gateway] = self::phpGraph('php:class:App\\Contracts\\PaymentGateway', 'php:class:app\\contracts\\PAYMENTGATEWAY', 'php:class:App\\Contracts\\Gone');
        [$exact, $anyCase, $gone] = $graph->doubles();

        assertSame($gateway, $graph->target($exact), 'An interface, reached through the class reference the worker writes for every double.');
        assertSame($gateway, $graph->target($anyCase), 'PHP class names are case-insensitive.');
        assertNull($graph->target($gone));
        assertSame('App\\Contracts\\PaymentGateway::chargeToken', $graph->member($gateway, 'CHARGETOKEN')?->canonicalName, 'So are PHP method names.');
    }

    public function testATypeScriptReferenceMatchesOnlyInItsOwnCase(): void
    {
        $graph = TestFidelityGraph::fromContributions(self::contributions());
        $class = $graph->target($graph->doubles()[4]);
        assertNotNull($class);

        assertNull($graph->member($class, 'GetProfile'));
        $double = self::double('tests/a.test.ts#double:8:1', ['kind' => 'class', 'export' => 'UserService']);
        $lower = TestFidelityGraph::fromContributions([...self::contributions(), new ScanContribution('knossos.typescript:file:tests/b.test.ts', [$double], [self::edge('doubles', $double, 'ts:class:src/userservice.ts#userservice')])]);
        assertNull($lower->target($lower->doubles()[5]));
    }

    public function testAPhpClassOutsideTheProjectIsExternal(): void
    {
        [$graph] = self::phpGraph('php:class:App\\Contracts\\PaymentGateway', 'php:class:Psr\\Log\\LoggerInterface', 'php:class:Monolog\\Logger');
        [$project, $unscanned, $vendored] = $graph->doubles();

        assertFalse($graph->isExternal($project));
        assertTrue($graph->isExternal($unscanned), 'A class the scan does not hold lives outside the project, in vendor/ or PHP itself.');
        assertTrue($graph->isExternal($vendored), 'A class declared under vendor/.');
    }

    public function testAPhpTypeReachesWhatItExtendsAndImplements(): void
    {
        $node = static fn(string $kind, string $name, string $file = 'src/a.php'): NodeFact => new NodeFact('php:' . $kind . ':' . $name, $kind, $name, $name, Origin::Ast, Confidence::Certain, new Evidence($file, 1, 1), []);
        $types = [$node('class', 'App\\Visa'), $node('class', 'App\\Card'), $node('interface', 'App\\Payable'), $node('trait', 'App\\Logs'), $node('class', 'App\\FileLog')];
        $graph = TestFidelityGraph::fromContributions([new ScanContribution('knossos.php:file:src', $types, [
            self::edge('extends', $types[0], 'php:class:APP\\CARD'),
            self::edge('uses_trait', $types[0], 'php:trait:App\\Logs'),
            self::edge('implements', $types[1], 'php:interface:App\\Payable'),
            self::edge('extends', $types[4], 'php:class:Monolog\\Logger'),
        ])]);

        assertTrue($graph->phpIsA('App\\Visa', 'App\\Payable'));
        assertTrue($graph->phpIsA('app\\visa', 'App\\Visa'));
        assertFalse($graph->phpIsA('App\\Card', 'App\\Visa'));
        assertFalse($graph->phpIsA('App\\Visa', 'App\\Logs'), 'A trait a class uses is no type it is.');
        assertNull($graph->phpIsA('App\\FileLog', 'App\\Payable'), 'A parent only vendor/ holds may implement anything.');
        assertNull($graph->phpIsA('App\\Card', 'Psr\\Log\\LoggerInterface'), 'A type only vendor/ holds.');
        assertNull($graph->phpIsA('Monolog\\Logger', 'App\\Payable'));
    }
}
