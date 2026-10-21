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

        $cycles = $queries->services()->cycleQueries->dependencyCycles($ids['project'])->data['cycles'];
        self::assertSame([['App\\A', 'App\\B']], array_map(static fn(array $cycle): array => self::names($cycle), $cycles), 'Only the cycle between two types counts.');

        $withSelfLoops = $queries->services()->cycleQueries->dependencyCycles($ids['project'], includeSelfLoops: true)->data['cycles'];
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

        $cycles = ArchitectureQueryService::forDatabase($pdo)->services()->cycleQueries->dependencyCycles($ids['project'], withinType: true)->data['cycles'];

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

        $cycles = ArchitectureQueryService::forDatabase($pdo)->services()->cycleQueries->dependencyCycles($ids['project'])->data['cycles'];

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

    /** The CLI takes it as `--within-type`, as the MCP tool takes `within_type`. */
    #[Group('cli')]
    public function testTheCliTakesWithinType(): void
    {
        $path = sys_get_temp_dir() . '/knossos-within-type-' . bin2hex(random_bytes(6)) . '.sqlite';
        try {
            $pdo = \Knossos\Store\SqliteConnection::open($path);
            (new \Knossos\Store\MigrationRunner($pdo, self::repositoryRoot() . '/migrations'))->migrate();
            [, $repository, $ids] = $this->storeFixture(pdo: $pdo);
            $this->graph($repository, $ids, $ids['scan'], withinType: true);
            $repository->completeScan($ids['project'], $ids['scan']);
            unset($pdo, $repository);
            $context = new \Knossos\Cli\CliCommandContext(new \Knossos\Cli\CliOptionParser(), new \Knossos\Cli\CliInputLoader(), new \Knossos\Runtime\RuntimeFactory(self::repositoryRoot()), $path);

            ob_start();
            try {
                $status = (new \Knossos\Cli\Command\QueryCommand())->run('dependency-cycles', [$ids['project']], ['within-type' => [''], 'json' => ['']], $context);
            } finally {
                $output = (string) ob_get_clean();
            }

            self::assertSame(0, $status);
            $cycles = json_decode($output, true, flags: JSON_THROW_ON_ERROR)['data']['cycles'];
            self::assertSame([false, true], array_column($cycles, 'within_type'));
        } finally {
            @unlink($path);
        }
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

    /**
     * The limit applies to each kind apart: the cycles inside one type can
     * neither cut a cycle between types nor mark that count as cut, and a cut
     * among them is said apart, as `within_type_truncated`.
     */
    #[Group('query')]
    public function testTheLimitCutsTheCyclesInsideOneTypeApart(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $this->graph($repository, $ids, $ids['scan'], withinType: true);
        $this->oneTypeCycle($repository, $ids, 'Tree');
        $repository->completeScan($ids['project'], $ids['scan']);
        $queries = ArchitectureQueryService::forDatabase($pdo);

        $one = $queries->services()->cycleQueries->dependencyCycles($ids['project'], limit: 1, withinType: true);
        self::assertSame([false, true], array_column($one->data['cycles'], 'within_type'));
        self::assertSame([], $one->data['bounds']['truncation_reasons']);
        self::assertFalse($one->truncated);
        self::assertTrue($one->data['bounds']['within_type_truncated']);

        $two = $queries->services()->cycleQueries->dependencyCycles($ids['project'], limit: 2, withinType: true);
        self::assertSame([false, true, true], array_column($two->data['cycles'], 'within_type'));
        self::assertFalse($two->data['bounds']['within_type_truncated']);
        self::assertArrayNotHasKey('within_type_truncated', $queries->services()->cycleQueries->dependencyCycles($ids['project'])->data['bounds']);
    }

    /** Asked for alongside self-loops, a symbol that calls itself is not a cycle between types. */
    #[Group('query')]
    public function testASelfLoopIsNotACycleBetweenTypes(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $n = $this->graph($repository, $ids, $ids['scan'], withinType: false);
        $alone = StableId::symbol($ids['project'], 'php', 'function', 'App\\recurse');
        $repository->saveNode($alone, $ids['project'], 'php', 'function', 'App\\recurse', 'recurse', null, $ids['file'], 1, 2, 'ast', 'certain', [], 'php:file:src/recurse.php', $ids['scan']);
        $this->edge($repository, $ids['project'], $ids['file'], $ids['scan'], 'calls', $alone, $alone);
        $repository->completeScan($ids['project'], $ids['scan']);

        $cycles = ArchitectureQueryService::forDatabase($pdo)->services()->cycleQueries->dependencyCycles($ids['project'], includeSelfLoops: true, withinType: true)->data['cycles'];

        self::assertSame([[2, false, null], [1, true, null]], array_map(static fn(array $cycle): array => [count($cycle['members']), $cycle['within_type'], $cycle['type']], $cycles));
        self::assertArrayHasKey('A', $n);
    }

    /**
     * The dashboard lists the largest of each kind, so the cycles inside one
     * type still show however many cycles there are between types, and says
     * when it cut them.
     */
    #[Group('query')]
    public function testTheDashboardListsSomeOfEachKind(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $this->graph($repository, $ids, $ids['scan'], withinType: true);
        foreach (range(1, 11) as $i) {
            $c = StableId::symbol($ids['project'], 'php', 'class', 'App\\C' . $i);
            $d = StableId::symbol($ids['project'], 'php', 'class', 'App\\D' . $i);
            foreach ([[$c, 'C'], [$d, 'D']] as [$id, $prefix]) {
                $repository->saveNode($id, $ids['project'], 'php', 'class', 'App\\' . $prefix . $i, $prefix . $i, null, $ids['file'], 1, 9, 'ast', 'certain', [], 'php:file:src/' . $prefix . $i . '.php', $ids['scan']);
            }
            $this->edge($repository, $ids['project'], $ids['file'], $ids['scan'], 'calls', $c, $d);
            $this->edge($repository, $ids['project'], $ids['file'], $ids['scan'], 'calls', $d, $c);
        }
        $repository->completeScan($ids['project'], $ids['scan']);

        $cycles = (new \Knossos\Query\DashboardService($pdo))->dashboard('/workspace/fixture-shop')['cycles'];

        self::assertSame([12, 1, false, false], [$cycles['count'], $cycles['within_type'], $cycles['truncated'], $cycles['within_type_truncated']]);
        self::assertSame(array_merge(array_fill(0, 10, false), [true]), array_column($cycles['largest'], 'within_type'));
    }

    /** A self-call inside a real cycle is recursion too, not one of the cycle's relationships. */
    #[Group('query')]
    public function testASelfCallIsNotListedAmongACyclesRelationships(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $n = $this->graph($repository, $ids, $ids['scan'], withinType: false);
        $repository->completeScan($ids['project'], $ids['scan']);

        $relationships = ArchitectureQueryService::forDatabase($pdo)->services()->cycleQueries->dependencyCycles($ids['project'])->data['cycles'][0]['relationships'];

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

        $gate = ArchitectureQueryService::forDatabase($pdo)->services()->gateQueries->qualityGate($ids['project'], $ids['scan'], ['new_cycles' => 0]);

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

    /** Class `$name` whose methods walk and walkAll call each other. */
    private function oneTypeCycle(GraphRepository $repository, array $ids, string $name): void
    {
        $project = $ids['project'];
        $type = StableId::symbol($project, 'php', 'class', 'App\\' . $name);
        $repository->saveNode($type, $project, 'php', 'class', 'App\\' . $name, $name, null, $ids['file'], 1, 9, 'ast', 'certain', [], 'php:file:src/' . $name . '.php', $ids['scan']);
        $methods = [];
        foreach (['walk', 'walkAll'] as $method) {
            $methods[] = $id = StableId::symbol($project, 'php', 'method', 'App\\' . $name . '::' . $method);
            $repository->saveNode($id, $project, 'php', 'method', 'App\\' . $name . '::' . $method, $method, null, $ids['file'], 2, 3, 'ast', 'certain', [], 'php:file:src/' . $name . '.php', $ids['scan']);
            $this->edge($repository, $project, $ids['file'], $ids['scan'], 'contains', $type, $id);
        }
        $this->edge($repository, $project, $ids['file'], $ids['scan'], 'calls', $methods[0], $methods[1]);
        $this->edge($repository, $project, $ids['file'], $ids['scan'], 'calls', $methods[1], $methods[0]);
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
