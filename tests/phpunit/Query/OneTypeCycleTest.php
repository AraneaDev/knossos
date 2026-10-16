<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Store\GraphRepository;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A tree walk whose methods call each other (walk_item -> walk_items ->
 * walk_mod -> walk_item) is recursion inside one type, not a tangle between
 * parts of the architecture, yet both cycles Knossos reported for itself were
 * exactly that. Such a cycle is left out unless self-loops are asked for, by
 * dependency_cycles and by the quality gate alike.
 */
final class OneTypeCycleTest extends KnossosTestCase
{
    #[Group('query')]
    public function testACycleInsideOneTypeIsLeftOut(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $this->graph($repository, $ids, $ids['scan'], withinType: true);
        $repository->completeScan($ids['project'], $ids['scan']);
        $queries = ArchitectureQueryService::forDatabase($pdo);

        $cycles = $queries->dependencyCycles($ids['project'])->data['cycles'];
        self::assertSame([['App\\A', 'App\\B']], array_map(static fn(array $cycle): array => self::names($cycle), $cycles), 'Only the cycle between two types counts.');

        $withSelfLoops = $queries->dependencyCycles($ids['project'], includeSelfLoops: true)->data['cycles'];
        self::assertCount(2, array_filter($withSelfLoops, static fn(array $cycle): bool => count($cycle['members']) > 1), 'Asked for, the recursion inside Walk is reported too.');
    }

    /**
     * Asked for, a cycle inside one type is kept and marked with its type,
     * after every cycle between types; a symbol that only calls itself is
     * still left out.
     */
    #[Group('query')]
    public function testWithinTypeKeepsTheCyclesInsideOneTypeMarkedAfterTheOthers(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $n = $this->graph($repository, $ids, $ids['scan'], withinType: true);
        $alone = StableId::symbol($ids['project'], 'php', 'function', 'App\\recurse');
        $repository->saveNode($alone, $ids['project'], 'php', 'function', 'App\\recurse', 'recurse', null, $ids['file'], 1, 2, 'ast', 'certain', [], 'php:file:src/recurse.php', $ids['scan']);
        $this->edge($repository, $ids['project'], $ids['file'], $ids['scan'], 'calls', $alone, $alone);
        $repository->completeScan($ids['project'], $ids['scan']);

        $cycles = ArchitectureQueryService::forDatabase($pdo)->dependencyCycles($ids['project'], withinType: true)->data['cycles'];

        self::assertSame(
            [[['App\\A', 'App\\B'], false, null], [['App\\Walk::walkItem', 'App\\Walk::walkItems'], true, 'App\\Walk']],
            array_map(static fn(array $cycle): array => [self::names($cycle), $cycle['within_type'], $cycle['type']], $cycles),
        );
        self::assertArrayHasKey('A', $n);
    }

    /** Without the option the answer is as it was: no cycle inside one type, no mark. */
    #[Group('query')]
    public function testTheDefaultAnswerCarriesNoMark(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $this->graph($repository, $ids, $ids['scan'], withinType: true);
        $repository->completeScan($ids['project'], $ids['scan']);

        $cycles = ArchitectureQueryService::forDatabase($pdo)->dependencyCycles($ids['project'])->data['cycles'];

        self::assertCount(1, $cycles);
        self::assertArrayNotHasKey('within_type', $cycles[0]);
    }

    /** The MCP tool takes the option, so the agent gets the pane's answer when it asks for it. */
    #[Group('query')]
    public function testTheMcpToolTakesWithinType(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $this->graph($repository, $ids, $ids['scan'], withinType: true);
        $repository->completeScan($ids['project'], $ids['scan']);
        $tools = new \Knossos\Mcp\ToolService(
            new \Knossos\Scan\ProjectScanService($pdo, self::repositoryRoot(), []),
            ArchitectureQueryService::forDatabase($pdo),
            new \Knossos\Maintenance\DatabaseMaintenanceService($pdo, ':memory:'),
            new \Knossos\Mcp\ResultEnricher(new \Knossos\Query\StalenessProbe($pdo), new \Knossos\Mcp\NextStepPlanner()),
        );

        $cycles = $tools->call('dependency_cycles', ['project_id' => $ids['project'], 'within_type' => true])->data['cycles'];

        self::assertSame([false, true], array_column($cycles, 'within_type'));
    }

    /**
     * The dashboard reads every cycle and counts them apart: `count` the
     * cycles between types, which the tile, the toasts and the gate go by,
     * `within_type` the rest; its list holds both, each marked.
     */
    #[Group('query')]
    public function testTheDashboardCountsTheCyclesInsideOneTypeApart(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $this->graph($repository, $ids, $ids['scan'], withinType: true);
        $repository->completeScan($ids['project'], $ids['scan']);

        $cycles = (new \Knossos\Query\DashboardService($pdo))->dashboard('/workspace/fixture-shop')['cycles'];

        self::assertSame([1, 1], [$cycles['count'], $cycles['within_type']]);
        self::assertSame([[false, null], [true, 'App\\Walk']], array_map(static fn(array $cycle): array => [$cycle['within_type'], $cycle['type']], $cycles['largest']));
    }

    /** A self-call inside a real cycle is recursion too, not one of the cycle's relationships. */
    #[Group('query')]
    public function testASelfCallIsNotListedAmongACyclesRelationships(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $n = $this->graph($repository, $ids, $ids['scan'], withinType: false);
        $repository->completeScan($ids['project'], $ids['scan']);

        $relationships = ArchitectureQueryService::forDatabase($pdo)->dependencyCycles($ids['project'])->data['cycles'][0]['relationships'];

        self::assertSame([], array_values(array_filter($relationships, static fn(array $edge): bool => $edge['source_id'] === $edge['target_id'])));
        self::assertNotSame([], $relationships);
        self::assertArrayHasKey('A', $n);
    }

    /** A new cycle inside one type does not fail new_cycles. */
    #[Group('query')]
    public function testTheGateDoesNotCountACycleInsideOneType(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $this->graph($repository, $ids, $ids['scan'], withinType: false);
        $repository->completeScan($ids['project'], $ids['scan']);
        $repository->archiveActiveSnapshot($ids['project'], hash('sha256', '{}'), 5);
        $next = StableId::scan($ids['project'], 'scan-2');
        $repository->createScan($next, $ids['project'], 'incremental', hash('sha256', 'scanner-set'));
        $this->graph($repository, $ids, $next, withinType: true);
        $repository->completeScan($ids['project'], $next);

        $gate = ArchitectureQueryService::forDatabase($pdo)->qualityGate($ids['project'], $ids['scan'], ['new_cycles' => 0]);

        self::assertSame(0, $gate->data['metrics']['new_cycles']);
        self::assertTrue($gate->data['passed']);
    }

    /**
     * Classes A and B calling each other (a self-call on A too); with
     * `$withinType`, also class Walk whose walkItem and walkItems call each other.
     *
     * @param array<string, string> $ids
     * @return array<string, string>
     */
    private function graph(GraphRepository $repository, array $ids, string $scan, bool $withinType): array
    {
        $project = $ids['project'];
        $n = [];
        foreach (['A', 'B', 'Walk'] as $class) {
            $n[$class] = StableId::symbol($project, 'php', 'class', 'App\\' . $class);
            $repository->saveNode($n[$class], $project, 'php', 'class', 'App\\' . $class, $class, null, $ids['file'], 1, 9, 'ast', 'certain', [], 'php:file:src/' . $class . '.php', $scan);
        }
        foreach (['walkItem', 'walkItems'] as $method) {
            $n[$method] = StableId::symbol($project, 'php', 'method', 'App\\Walk::' . $method);
            $repository->saveNode($n[$method], $project, 'php', 'method', 'App\\Walk::' . $method, $method, null, $ids['file'], 2, 3, 'ast', 'certain', [], 'php:file:src/Walk.php', $scan);
            $this->edge($repository, $project, $ids['file'], $scan, 'contains', $n['Walk'], $n[$method]);
        }
        foreach ([['A', 'B'], ['B', 'A'], ['A', 'A']] as [$from, $to]) {
            $this->edge($repository, $project, $ids['file'], $scan, 'calls', $n[$from], $n[$to]);
        }
        if ($withinType) {
            $this->edge($repository, $project, $ids['file'], $scan, 'calls', $n['walkItem'], $n['walkItems']);
            $this->edge($repository, $project, $ids['file'], $scan, 'calls', $n['walkItems'], $n['walkItem']);
        }

        return $n;
    }

    private function edge(GraphRepository $repository, string $project, string $file, string $scan, string $kind, string $source, string $target): void
    {
        $repository->saveEdge(StableId::edge($project, $kind, $source, $target, 'cycle'), $project, $kind, $source, $target, $file, 1, 1, 'ast', 'certain', [], 'php:file:src/Cycle.php', $scan);
    }

    /** @param array<string, mixed> $cycle @return list<string> */
    private static function names(array $cycle): array
    {
        $names = array_map(static fn(mixed $member): string => is_array($member) ? (string) ($member['canonical_name'] ?? '') : (string) $member, $cycle['members']);
        sort($names);

        return $names;
    }
}
