<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Store\SnapshotBounds;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\Support\SyntheticGraph;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * The quality gate holds the baseline and the active graph in memory at once,
 * so its row limit is what keeps it inside the core's 1G. Measured on
 * 2026-10-16: two graphs of 200,000 nodes and 400,000 edges peaked at 819 MB,
 * too close; at the limit, 300,000 edges, they stay well under 768 MB.
 */
final class GateScaleTest extends KnossosTestCase
{
    #[Group('query')]
    #[RunInSeparateProcess]
    public function testTheGateComparesTwoGraphsAtTheRowLimitInside768Megabytes(): void
    {
        ini_set('memory_limit', '768M');
        [$pdo, $repository, $ids] = $this->storeFixture();
        $pdo->beginTransaction();
        // Two edges out of each node: the edge table ends just under the limit.
        SyntheticGraph::seed($pdo, $ids, intdiv(SnapshotBounds::MAX_ROWS_PER_TABLE, 2) - 1, 2);
        $pdo->commit();
        $repository->completeScan($ids['project'], $ids['scan']);
        $repository->archiveActiveSnapshot($ids['project'], hash('sha256', '{}'), 5);
        $next = StableId::scan($ids['project'], 'scan-2');
        $repository->createScan($next, $ids['project'], 'incremental', hash('sha256', 'scanner-set'));
        $repository->completeScan($ids['project'], $next);

        $complete = (int) $pdo->query('SELECT complete FROM scan_snapshots')->fetchColumn();
        $gate = ArchitectureQueryService::forDatabase($pdo)->services()->gateQueries->qualityGate($ids['project'], $ids['scan'], ['new_cycles' => 0]);

        self::assertSame(1, $complete, 'The archive at the limit holds its facts.');
        self::assertTrue($gate->data['passed']);
        self::assertSame(0, $gate->data['metrics']['new_cycles']);
    }
}
