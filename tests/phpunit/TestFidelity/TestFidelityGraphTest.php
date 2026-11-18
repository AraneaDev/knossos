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
}
