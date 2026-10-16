<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\QueryBounds;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\Support\SyntheticGraph;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * The whole-graph queries at their own ceilings fit well inside the core's 1G.
 *
 * max_nodes reaches QueryBounds::MAX_NODES and max_edges QueryBounds::MAX_EDGES,
 * and both are the defaults of dependency_cycles and architecture_health.
 * Measured on 2026-10-16 at 200,000 nodes and about 400,000 edges: health
 * peaks near 300 MB, cycles near 250 MB. A walk that keeps a full row per node,
 * or a named array per edge, needs well over 512 MB at that size. Each test
 * runs in a process of its own, so the limit it sets measures the query rather
 * than whatever earlier tests left in the heap.
 */
final class GraphScaleTest extends KnossosTestCase
{
    #[Group('query')]
    #[RunInSeparateProcess]
    public function testArchitectureHealthAtItsDefaultNodeCeilingFitsIn512Megabytes(): void
    {
        ini_set('memory_limit', '512M');
        [$pdo, $project] = $this->graphAtTheCeiling();

        // At this size the walk takes about four seconds locally, so a loaded
        // CI runner may stop it at the five-second maximum: a time limit is
        // allowed here, a node or edge limit is not. Memory is what this pins.
        $result = ArchitectureQueryService::forDatabase($pdo)->architectureHealth($project, timeoutMs: 5000);

        assertSame([], array_values(array_diff($result->data['bounds']['truncation_reasons'], ['result_limit', 'time_limit', 'cycle_scan'])));
        assertSame(QueryBounds::MAX_NODES, $result->data['bounds']['max_nodes']);
        assertSame(20, count($result->data['hubs']));
    }

    #[Group('query')]
    #[RunInSeparateProcess]
    public function testDependencyCyclesAtItsNodeAndEdgeCeilingsFitsIn512Megabytes(): void
    {
        ini_set('memory_limit', '512M');
        [$pdo, $project] = $this->graphAtTheCeiling();

        $result = ArchitectureQueryService::forDatabase($pdo)->dependencyCycles($project, timeoutMs: 5000);

        assertSame([], $result->data['bounds']['truncation_reasons']);
        assertSame(QueryBounds::MAX_NODES, $result->data['bounds']['nodes_examined']);
        assertSame([3], array_column($result->data['cycles'], 'size'));
    }

    /**
     * Synthetic nodes up to the node ceiling beside the fixture's two, two edges out of each, and one three-member cycle.
     *
     * @return array{PDO, string}
     */
    private function graphAtTheCeiling(): array
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $pdo->beginTransaction();
        SyntheticGraph::seed($pdo, $ids, QueryBounds::MAX_NODES - 2, 2);
        SyntheticGraph::edge($pdo, $ids, 'edge:back', 'calls', 160_002, 160_000);
        $pdo->commit();
        $repository->completeScan($ids['project'], $ids['scan']);

        return [$pdo, $ids['project']];
    }
}
