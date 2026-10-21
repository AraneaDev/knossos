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
 * snapshot_diff compares two whole graphs. It held every full row of both,
 * about 6 KB a row: measured on 2026-10-16 at 300,000 edges it peaked at
 * 1.8 GB with 1% of the rows changed and 2.4 GB with all of them, so it ran
 * out of the core's 1G past about 120,000 rows. It now keeps a hash and the
 * few fields its counts need, and reads whole rows only for the changes it
 * reports.
 */
final class DiffScaleTest extends KnossosTestCase
{
    #[Group('query')]
    #[RunInSeparateProcess]
    public function testADiffOfTwoGraphsAtTheRowLimitWithEveryRowChangedFitsIn768Megabytes(): void
    {
        ini_set('memory_limit', '768M');
        [$pdo, $repository, $ids] = $this->storeFixture();
        $pdo->beginTransaction();
        SyntheticGraph::seed($pdo, $ids, intdiv(SnapshotBounds::MAX_ROWS_PER_TABLE, 2) - 1, 2);
        $pdo->commit();
        $repository->completeScan($ids['project'], $ids['scan']);
        $repository->archiveActiveSnapshot($ids['project'], hash('sha256', '{}'), 5);
        $next = StableId::scan($ids['project'], 'scan-2');
        $repository->createScan($next, $ids['project'], 'incremental', hash('sha256', 'scanner-set'));
        $pdo->exec("UPDATE nodes SET display_name = display_name || 'x', start_line = 2, end_line = 2");
        $pdo->exec('UPDATE edges SET start_line = 2, end_line = 2');
        $repository->completeScan($ids['project'], $next);

        $diff = ArchitectureQueryService::forDatabase($pdo)->services()->diffQueries->snapshotDiff($ids['project'], $ids['scan'], maxChanges: 1000);

        $counts = $diff->data['changes'];
        self::assertSame(intdiv(SnapshotBounds::MAX_ROWS_PER_TABLE, 2) + 1, $counts['components']['counts']['changed']);
        self::assertGreaterThan(SnapshotBounds::MAX_ROWS_PER_TABLE - 100, $counts['relationships']['counts']['moved']);
        self::assertSame(1000, $diff->data['bounds']['reported_changes']);
        self::assertTrue($diff->truncated);
    }
}
