<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use InvalidArgumentException;
use Knossos\Maintenance\DatabaseMaintenanceService;
use Knossos\Mcp\NextStepPlanner;
use Knossos\Mcp\ResultEnricher;
use Knossos\Mcp\ToolService;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\ImpactAnalysisQuery;
use Knossos\Query\StalenessProbe;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\Support\PaneAgreementGraph;
use Knossos\Tests\Phpunit\Support\QueryCountingStatement;
use PHPUnit\Framework\Attributes\Group;

use function PHPUnit\Framework\assertArrayNotHasKey;
use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertNotContains;
use function PHPUnit\Framework\assertSame;

/**
 * The options the pane's rings needed, on the core query the agent uses:
 * seeding the walk with a class's members, lifting framework hooks, ring
 * counts, the edges the walk read, and bounds the caller sets.
 */
#[Group('query')]
final class ImpactAnalysisOptionsTest extends KnossosTestCase
{
    use PaneAgreementGraph;

    public function testAClassReachedOnlyThroughAStaticMethodListsTheCallerViaThatMember(): void
    {
        [$pdo, , $projectId] = $this->paneAgreementGraph();

        $rows = self::byName(ArchitectureQueryService::forDatabase($pdo)->impactAnalysis($projectId, 'App\\Target')->data['dependants']);

        assertSame('App\\Target::make', $rows['App\\StaticCaller']['via_member'] ?? null);
        assertSame(1, $rows['App\\StaticCaller']['distance']);
        assertArrayNotHasKey('via_member', $rows['App\\User']);
    }

    public function testWithoutMemberSeedingTheStaticCallerIsNoDependant(): void
    {
        [$pdo, , $projectId] = $this->paneAgreementGraph();

        $rows = self::byName(ArchitectureQueryService::forDatabase($pdo)->impactAnalysis($projectId, 'App\\Target', seedMembers: false)->data['dependants']);

        assertArrayNotHasKey('App\\StaticCaller', $rows);
        assertContains('App\\User', array_keys($rows));
    }

    public function testTheMcpToolSwitchesMemberSeedingOff(): void
    {
        [$pdo, , $projectId] = $this->paneAgreementGraph();
        $tools = new ToolService(
            new ProjectScanService($pdo, self::repositoryRoot(), []),
            ArchitectureQueryService::forDatabase($pdo),
            new DatabaseMaintenanceService($pdo, ':memory:'),
            new ResultEnricher(new StalenessProbe($pdo), new NextStepPlanner()),
        );

        $on = $tools->call('impact_analysis', ['project_id' => $projectId, 'symbol' => 'App\\Target']);
        $off = $tools->call('impact_analysis', ['project_id' => $projectId, 'symbol' => 'App\\Target', 'seed_members' => false]);

        // A compact answer names each dependant by its component_legend key.
        assertContains('App\\StaticCaller', array_column($on->data['dependants'], 'node'));
        assertNotContains('App\\StaticCaller', array_column($off->data['dependants'], 'node'));
    }

    /**
     * The ring counts, tests apart: `App\Maybe`, `App\StaticCaller` and
     * `App\User` one hop out, `App\Maybe` on a probable edge; `App\Chain1`
     * and the test two hops out; the rest of the chain further.
     */
    public function testRingsCountTheDependantsByDistanceWithTestsApart(): void
    {
        [$pdo, , $projectId] = $this->paneAgreementGraph();

        $rings = ArchitectureQueryService::forDatabase($pdo)->impactAnalysis($projectId, 'App\\Target', rings: true)->data['rings'];

        assertSame([
            ['hop' => 1, 'count' => 3, 'tests' => 0, 'by_confidence' => ['certain' => 2, 'probable' => 1, 'possible' => 0]],
            ['hop' => 2, 'count' => 1, 'tests' => 1, 'by_confidence' => ['certain' => 1, 'probable' => 0, 'possible' => 0]],
            ['hop' => 3, 'count' => 2, 'tests' => 0, 'by_confidence' => ['certain' => 2, 'probable' => 0, 'possible' => 0]],
        ], $rings);
    }

    public function testRingsAndLinksAreOnlyThereWhenAskedFor(): void
    {
        [$pdo, , $projectId] = $this->paneAgreementGraph();

        $data = ArchitectureQueryService::forDatabase($pdo)->impactAnalysis($projectId, 'App\\Target')->data;

        assertArrayNotHasKey('rings', $data);
        assertArrayNotHasKey('links', $data);
    }

    /**
     * Every edge the walk read, a rediscovery included: the test's edge into
     * `App\User` is there although the test is reached once.
     */
    public function testLinksHoldEveryEdgeTheWalkRead(): void
    {
        [$pdo, , $projectId] = $this->paneAgreementGraph();

        $data = (new ImpactAnalysisQuery($pdo))->impactAnalysis($projectId, 'App\\Target', links: true)->data;
        $names = [];
        foreach ($pdo->query('SELECT id, canonical_name FROM nodes')->fetchAll(\PDO::FETCH_NUM) as [$id, $name]) {
            $names[(string) $id] = (string) $name;
        }
        $links = array_map(static fn(array $link): string => $names[$link[0]] . ' > ' . $names[$link[1]], $data['links']);
        sort($links);

        assertSame([
            'App\\Chain1 > App\\User',
            'App\\Chain2 > App\\Chain1',
            'App\\Chain3 > App\\Chain2',
            'App\\Maybe > App\\Target',
            'App\\StaticCaller > App\\Target::make',
            'App\\User > App\\Target',
            'Tests\\TargetTest > App\\User',
        ], $links);
    }

    public function testTheCallerSetsTheBoundsAndTheyAreReported(): void
    {
        [$pdo, , $projectId] = $this->paneAgreementGraph();

        $bounds = (new ImpactAnalysisQuery($pdo))->impactAnalysis($projectId, 'App\\Target', 6, 1500, timeoutMs: 2000)->data['bounds'];

        assertSame([1500, 2000], [$bounds['limit'], $bounds['timeout_ms']]);
    }

    public function testALimitPastTheQuerysOwnBoundIsRefused(): void
    {
        [$pdo, , $projectId] = $this->paneAgreementGraph();

        $this->expectException(InvalidArgumentException::class);
        (new ImpactAnalysisQuery($pdo))->impactAnalysis($projectId, 'App\\Target', 6, ImpactAnalysisQuery::MAX_LIMIT + 1);
    }

    /**
     * A model's `newFactory` is called by the framework whenever the model
     * is used: what uses `Order` depends on `OrderFactory` through it. With
     * the lift switched off, nothing names `newFactory`'s callers.
     */
    public function testAFrameworkHookIsDependedOnByWhatUsesItsClass(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-impact-hook-' . bin2hex(random_bytes(6));
        $files = [
            'app/Models/Order.php' => '<?php namespace App\\Models; use Database\\Factories\\OrderFactory; use Illuminate\\Database\\Eloquent\\Model;'
                . ' final class Order extends Model { protected static function newFactory(): OrderFactory { return OrderFactory::new(); } public function total(): int { return 0; } }',
            'database/factories/OrderFactory.php' => '<?php namespace Database\\Factories; final class OrderFactory { public static function new(): self { return new self(); } }',
            'app/Services/Checkout.php' => '<?php namespace App\\Services; use App\\Models\\Order; final class Checkout { public function pay(Order $order): int { return $order->total(); } }',
        ];
        foreach ($files as $relative => $contents) {
            @mkdir(dirname($root . '/' . $relative), 0o777, true);
            file_put_contents($root . '/' . $relative, $contents);
        }

        try {
            $pdo = $this->freshTestDatabase();
            $projectId = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root)->projectId;
            $query = new ImpactAnalysisQuery($pdo);

            $lifted = self::byName($query->impactAnalysis($projectId, 'Database\\Factories\\OrderFactory')->data['dependants']);
            $plain = self::byName($query->impactAnalysis($projectId, 'Database\\Factories\\OrderFactory', frameworkHooks: false)->data['dependants']);

            assertContains('App\\Models\\Order::newFactory', array_keys($plain));
            assertNotContains('App\\Services\\Checkout::pay', array_keys($plain));
            assertSame('probable', $lifted['App\\Services\\Checkout::pay']['path_confidence'] ?? null);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * Only a method named like a framework hook can be one: a walk through
     * `App\\Target::make` never asks which class declares it or what that
     * class inherits.
     */
    public function testAWalkThroughNoMethodNamedLikeAHookNeverLooksForHooks(): void
    {
        [$pdo, , $projectId] = $this->paneAgreementGraph();
        $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [QueryCountingStatement::class, []]);
        QueryCountingStatement::reset();

        (new ImpactAnalysisQuery($pdo))->impactAnalysis($projectId, 'App\\Target');

        assertSame(0, QueryCountingStatement::count("e.kind = 'contains' AND n.kind = 'method'"));
    }

    /**
     * @param list<array<string, mixed>> $dependants
     * @return array<string, array<string, mixed>>
     */
    private static function byName(array $dependants): array
    {
        $rows = [];
        foreach ($dependants as $row) {
            $rows[(string) $row['node']['canonical_name']] = $row;
        }

        return $rows;
    }
}
