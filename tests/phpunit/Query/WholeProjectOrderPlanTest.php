<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PDOStatement;

use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * The reads that walk a whole project's edges in a fixed order take that order
 * from an index where one gives most of it, and sort only what is left: each
 * source's few edges.
 *
 * Sorted whole, the order cost a sort of every row before the first arrived,
 * spilled to a temporary file: on a scan of this repository the policy check
 * spent 1.4 s before its first edge, past its one-second default, so
 * check_architecture judged one edge and reported a time limit. Location
 * suggestions spent 0.6 s on their edges, and the trend of the active graph
 * 1.5 s sorting its edges by id.
 */
final class WholeProjectOrderPlanTest extends KnossosTestCase
{
    /** A policy over every dependency kind reads in source order, sorting each source's edges alone. */
    #[Group('query')]
    public function testABroadPolicyCheckReadsInSourceOrder(): void
    {
        [$pdo, $project, $boundaries] = $this->callGraph();

        $plans = $this->plansOf($pdo, 'e.confidence, e.origin, e.start_line', static function (ArchitectureQueryService $queries) use ($project, $boundaries): void {
            $queries->services()->policyQueries->checkArchitecture($project, [['id' => 'odd-not-even', 'from_boundary' => $boundaries[0], 'deny_targets' => [$boundaries[1]]]], timeoutMs: 5000);
        });

        foreach ($plans as $plan) {
            assertSame(true, str_contains($plan, 'edges_project_source_idx'), $plan);
            assertSame(false, str_contains($plan, 'USE TEMP B-TREE FOR ORDER BY'), $plan);
        }
    }

    /** A policy over a kind the project has few of seeks that kind, rather than walking every edge to find them. */
    #[Group('query')]
    public function testANarrowPolicyCheckKeepsTheKindIndex(): void
    {
        [$pdo, $project, $boundaries] = $this->callGraph();

        $plans = $this->plansOf($pdo, 'e.confidence, e.origin, e.start_line', static function (ArchitectureQueryService $queries) use ($project, $boundaries): void {
            $queries->services()->policyQueries->checkArchitecture($project, [['id' => 'odd-not-even', 'from_boundary' => $boundaries[0], 'deny_targets' => [$boundaries[1]], 'edge_kinds' => ['imports']]], timeoutMs: 5000);
        });

        foreach ($plans as $plan) {
            assertSame(true, str_contains($plan, 'edges_project_kind_idx'), $plan);
        }
    }

    #[Group('query')]
    public function testALocationSuggestionReadsItsEdgesInSourceOrder(): void
    {
        [$pdo, $project] = $this->callGraph();

        $plans = $this->plansOf($pdo, 'SELECT source_id, target_id FROM edges', static function (ArchitectureQueryService $queries) use ($project): void {
            $queries->services()->locationQueries->suggestLocation($project, 'a step that tallies the checkout', timeoutMs: 5000);
        });

        foreach ($plans as $plan) {
            assertSame(false, str_contains($plan, 'USE TEMP B-TREE FOR ORDER BY'), $plan);
        }
    }

    #[Group('query')]
    public function testTheTrendOfTheActiveGraphReadsItsRowsWithoutSortingThem(): void
    {
        [$pdo, $project] = $this->callGraph();

        $plans = $this->plansOf($pdo, 'FROM edges WHERE project_id = :project', static function (ArchitectureQueryService $queries) use ($project): void {
            $queries->services()->gateQueries->architectureTrends($project, 5);
        });

        foreach ($plans as $plan) {
            assertSame(false, str_contains($plan, 'USE TEMP B-TREE FOR ORDER BY'), $plan);
        }
    }

    /**
     * A project of 3,540 calls between 60 functions, enough for a sample of
     * its edges to judge, split between two boundaries by parity.
     *
     * @return array{0: PDO, 1: string, 2: list<string>}
     */
    private function callGraph(): array
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $project = $ids['project'];
        $boundaries = [StableId::boundary($project, 'Odd', 'explicit'), StableId::boundary($project, 'Even', 'explicit')];
        $repository->saveBoundary($boundaries[0], $project, 'Odd', ['path_prefix' => 'src/Odd'], 'explicit', $ids['scan']);
        $repository->saveBoundary($boundaries[1], $project, 'Even', ['path_prefix' => 'src/Even'], 'explicit', $ids['scan']);
        $functions = [];
        for ($index = 0; $index < 60; ++$index) {
            $name = sprintf('App\\step%d', $index);
            $functions[] = $id = StableId::symbol($project, 'php', 'function', $name);
            $repository->saveNode($id, $project, 'php', 'function', $name, sprintf('step%d', $index), null, $ids['file'], 1, 9, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
            $repository->saveBoundaryMembership($boundaries[$index % 2], $project, $id, $ids['scan']);
        }
        foreach ($functions as $caller) {
            foreach ($functions as $callee) {
                if ($caller !== $callee) {
                    $repository->saveEdge(StableId::edge($project, 'calls', $caller, $callee, '1'), $project, 'calls', $caller, $callee, $ids['file'], 2, 2, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
                }
            }
        }
        $repository->completeScan($project, $ids['scan']);

        return [$pdo, $project, $boundaries];
    }

    /**
     * The plans, one line each, of the statements containing `$needle` that `$run` makes.
     *
     * @param callable(ArchitectureQueryService): void $run
     * @return list<string>
     */
    private function plansOf(PDO $pdo, string $needle, callable $run): array
    {
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [RecordingStatement::class]);
        RecordingStatement::drain();
        try {
            $run(ArchitectureQueryService::forDatabase($pdo));
        } finally {
            $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PDOStatement::class]);
        }
        $plans = [];
        foreach (RecordingStatement::drain() as [$sql, $params]) {
            if (str_contains($sql, $needle)) {
                $plans[] = implode(' | ', RecordingStatement::plan($pdo, $sql, $params));
            }
        }
        assertNotSame([], $plans, sprintf('No statement containing "%s" ran, so this test checked nothing.', $needle));

        return $plans;
    }
}
