<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity;

use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\EdgeFact;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\TestFidelity\Rules\GhostMemberRule;
use Knossos\TestFidelity\TestFidelityGraph;
use Knossos\TestFidelity\TestFinding;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\TestFidelity\Rules\DriftFixtures;
use Knossos\Tests\Phpunit\TestFidelity\Rules\PythonFixtures;

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

    /**
     * Python doubles from `tests/test_repo.py`, one per target reference given (`null`: no edge), each of kind `$kind`.
     *
     * @param list<string|null> $references
     * @param list<NodeFact> $production
     */
    private static function pythonGraph(array $references, array $production = [], string $kind = 'class'): TestFidelityGraph
    {
        $doubles = $edges = [];
        foreach ($references as $index => $reference) {
            $double = PythonFixtures::node('test_double', 'tests/test_repo.py#double:' . ($index + 1) . ':5', [
                'framework' => 'unittest', 'pattern' => 'unittest.mock.patch.object', 'target' => ['kind' => $kind, 'export' => 'app.repo.Repo'], 'automock' => false, 'invocations' => 0, 'members' => [],
            ], 'tests/test_repo.py');
            $doubles[] = $double;
            if ($reference !== null) {
                $edges[] = PythonFixtures::edge('doubles', $double, $reference);
            }
        }

        return TestFidelityGraph::fromContributions([
            new ScanContribution('knossos.python:file:src', $production, []),
            new ScanContribution('knossos.python:file:tests/test_repo.py', $doubles, $edges),
        ]);
    }

    public function testAnUnemittedPythonTargetIsExternal(): void
    {
        $repo = PythonFixtures::node('class', 'app.repo.Repo', ['member_names' => []]);
        $installed = PythonFixtures::node('class', 'requests.Session', ['member_names' => []], '.venv/lib/python3.12/site-packages/requests/sessions.py');
        $graph = self::pythonGraph(['py:class:app.repo.Repo', 'py:class:app.repo.Gone', 'py:module:app.gone', 'py:external_symbol:os.path', 'py:class:requests.Session', null], [$repo, $installed]);
        [$held, $gone, $module, $symbol, $venv, $unbound] = $graph->doubles();

        assertFalse($graph->isExternal($held), 'a class the scan holds');
        assertTrue($graph->isExternal($gone), 'a Python class no contribution emits');
        assertTrue($graph->isExternal($module), 'a Python module no contribution emits');
        assertTrue($graph->isExternal($symbol), 'an external symbol');
        assertTrue($graph->isExternal($venv), 'a class under site-packages/');
        assertFalse($graph->isExternal($unbound), 'no edge and no specifier');
    }

    public function testSitePackagesIsOutsideOnlyForPython(): void
    {
        $node = new NodeFact('ts:class:x', 'class', 'x', 'x', Origin::Ast, Confidence::Certain, new Evidence('lib/site-packages/x.ts', 1, 1), []);
        $double = self::double('tests/a.test.ts#double:1:1', ['kind' => 'class', 'export' => 'x']);
        $graph = TestFidelityGraph::fromContributions([new ScanContribution('knossos.typescript:file:x', [$node, $double], [self::edge('doubles', $double, 'ts:class:x')])]);

        assertFalse($graph->isExternal($graph->doubles()[0]));
    }

    public function testIsRust(): void
    {
        $rust = new NodeFact('rust:class:src/lib.rs#Store', 'class', 'src/lib.rs#Store', 'Store', Origin::Ast, Confidence::Certain, new Evidence('src/lib.rs', 1, 1));

        assertTrue(TestFidelityGraph::isRust($rust));
        assertFalse(TestFidelityGraph::isRust(PythonFixtures::node('class', 'app.repo.Repo')));
        assertFalse(TestFidelityGraph::isPython($rust));
    }

    public function testKnownPythonBasesAreSeenAndAddTheirMetaclassNames(): void
    {
        $abstract = PythonFixtures::node('class', 'app.shapes.Shape', ['member_names' => ['area']]);
        $square = PythonFixtures::node('class', 'app.shapes.Square', ['member_names' => ['side']]);
        $colour = PythonFixtures::node('class', 'app.shapes.Colour', ['member_names' => ['RED']]);
        $graph = TestFidelityGraph::fromContributions([new ScanContribution('knossos.python:file:src/app/shapes.py', [$abstract, $square, $colour], [
            PythonFixtures::edge('extends', $abstract, 'py:external_symbol:abc.ABC'),
            PythonFixtures::edge('extends', $square, 'py:class:app.shapes.Shape'),
            PythonFixtures::edge('extends', $colour, 'py:class:enum.Enum'),
        ])]);

        assertTrue(TestFidelityGraph::isPython($square));
        assertFalse(TestFidelityGraph::isPhp($square));
        assertSame($square, $graph->pythonClass('app.shapes.Square'));
        assertNull($graph->pythonClass('app.shapes.Circle'));
        assertFalse($graph->hasUnseenAncestor($square));
        assertTrue($graph->hasClosedLineage($square));
        assertSame(['app.shapes.Square', 'app.shapes.Shape'], array_map(static fn(NodeFact $type): string => $type->canonicalName, $graph->lineage($square)));
        assertSame(['_abc_cache', '_abc_caches_clear', '_abc_impl', '_abc_negative_cache', '_abc_negative_cache_version', '_abc_registry', '_abc_registry_clear', '_dump_registry', 'register'], $graph->knownBaseNames($square));
        // Two known bases, one a Protocol: each name once, sorted.
        $both = TestFidelityGraph::fromContributions([new ScanContribution('knossos.python:file:src', [$square], [
            PythonFixtures::edge('extends', $square, 'py:external_symbol:typing.Protocol'),
            PythonFixtures::edge('extends', $square, 'py:class:abc.ABC'),
        ])]);
        assertSame(['_abc_cache', '_abc_caches_clear', '_abc_impl', '_abc_negative_cache', '_abc_negative_cache_version', '_abc_registry', '_abc_registry_clear', '_dump_registry', '_is_protocol', '_is_runtime_protocol', 'register'], $both->knownBaseNames($square));
        assertTrue($graph->hasUnseenAncestor($colour));
        assertFalse($graph->hasClosedLineage($colour));
        assertSame([], $graph->knownBaseNames($colour));
    }

    /**
     * One FQCN declared under `vendor/` and in the project is read from the
     * project's declaration, whichever comes first, and nothing the vendor
     * copy declares leaks into it; two project declarations that differ in
     * more than their lines leave the type unknown, so nothing is judged
     * against it (G3-11).
     */
    public function testAPhpTypeDeclaredTwiceIsReadFromTheProjectsOwnDeclaration(): void
    {
        $declare = static function (string $file, array $methods, array $attributes = ['member_names' => []]): ScanContribution {
            $type = DriftFixtures::phpType('class', 'App\Repo', $attributes, $file);
            $nodes = array_map(static fn(string $name): NodeFact => DriftFixtures::phpMethod($type, $name, ['signature' => ['params' => []]]), $methods);

            return new ScanContribution('knossos.php:file:' . $file, [$type, ...$nodes], array_map(static fn(NodeFact $node): EdgeFact => DriftFixtures::edge('contains', $type, $node->localId), $nodes));
        };
        $double = new NodeFact('php:test_double:tests/RepoTest.php#double:6:3', 'test_double', 'tests/RepoTest.php#double:6:3', 'createMock', Origin::Ast, Confidence::Certain, new Evidence('tests/RepoTest.php', 6, 6), [
            'framework' => 'phpunit', 'pattern' => 'createMock', 'target' => ['kind' => 'class', 'export' => 'App\Repo'], 'automock' => false, 'invocations' => 0,
            'members' => [['name' => 'gone', 'line' => 7, 'api' => 'method', 'returns' => []]],
        ]);
        $test = new ScanContribution('knossos.php:file:tests/RepoTest.php', [$double], [self::edge('doubles', $double, 'php:class:App\Repo')]);
        $graph = static fn(ScanContribution ...$contributions): TestFidelityGraph => TestFidelityGraph::fromContributions([...$contributions, $test]);
        $ghosts = static fn(TestFidelityGraph $graph): array => array_map(static fn(TestFinding $finding): string => $finding->message, [...(new GhostMemberRule())->findings($graph)]);
        $vendor = $declare('vendor/acme/repo/src/Repo.php', ['find', 'gone'], ['member_names' => ['find', 'gone']]);
        $src = $declare('src/Repo.php', ['find'], ['member_names' => ['find']]);

        $preferred = $graph($vendor, $src);
        $repo = $preferred->phpType('app\repo');
        assertSame('src/Repo.php', $repo?->evidence->relativePath);
        assertSame(['find'], array_map(static fn(NodeFact $child): string => $child->displayName, $preferred->children($repo)));
        assertNull($preferred->member($repo, 'gone'));
        assertSame(['createMock stubs gone, which Repo no longer has'], $ghosts($graph($src, $vendor)), 'The project declaration is read in either order.');

        $copy = $declare('src/Copy/Repo.php', ['find'], ['member_names' => ['find']]);
        assertSame('src/Repo.php', $graph($src, $copy)->phpType('App\Repo')?->evidence->relativePath, 'Two project declarations alike but for where they are keep the first.');
        $other = $declare('lib/Repo.php', ['find', 'gone'], ['member_names' => ['find', 'gone']]);
        $differing = $graph($src, $vendor, $other);
        assertNull($differing->phpType('App\Repo'));
        assertFalse($differing->has('php:class:App\Repo'));
        assertFalse($differing->has('php:method:App\Repo::find'));
        assertSame([], $ghosts($differing), 'Two project declarations that differ leave the type unknown: silence.');
        assertSame('vendor/acme/repo/src/Repo.php', $graph($vendor)->phpType('App\Repo')?->evidence->relativePath, 'A vendor declaration alone is still read.');
    }

    public function testAProjectNodeUnderAKnownBaseNameIsTheNodeItself(): void
    {
        // A project's own `abc.ABC` (a module that shadows the stdlib) is walked like any class.
        $own = PythonFixtures::node('class', 'abc.ABC', ['member_names' => ['extra'], 'member_names_open' => true]);
        $child = PythonFixtures::node('class', 'app.Child', ['member_names' => []]);
        $graph = TestFidelityGraph::fromContributions([new ScanContribution('knossos.python:file:src', [$own, $child], [PythonFixtures::edge('extends', $child, 'py:class:abc.ABC')])]);

        assertFalse($graph->hasClosedLineage($child));
        assertSame([], $graph->knownBaseNames($child));
    }
}
