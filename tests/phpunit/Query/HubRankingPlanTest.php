<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PDOStatement;

use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * The hub ranking counts degrees and reads boundary memberships from indexes
 * alone, without visiting a table row per edge or per membership.
 *
 * The degree counts filter out enriched edges by origin, which the endpoint
 * indexes did not hold, so every selected edge was read from its table row: on
 * a scan of this repository (100,000 edges) the two counts took 190 ms each
 * against 37 ms from the index, and the membership read 150 ms against 30 ms.
 * The whole ranking then ran past architecture_health's one-second default,
 * cutting its edge walk short and its hotspot scores with it.
 */
final class HubRankingPlanTest extends KnossosTestCase
{
    #[Group('query')]
    public function testTheHubRankingReadsItsDegreesAndBoundariesFromIndexesAlone(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $project = $ids['project'];
        $repository->completeScan($project, $ids['scan']);

        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [RecordingStatement::class]);
        RecordingStatement::drain();
        try {
            $queries = ArchitectureQueryService::forDatabase($pdo)->services()->healthQueries;
            $queries->architectureHealth($project, limit: 10);
            $queries->hubRanking($project);
        } finally {
            $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PDOStatement::class]);
        }

        $planned = 0;
        $rowReads = [];
        foreach (RecordingStatement::drain() as [$sql, $params]) {
            $degrees = str_contains($sql, 'COUNT(*) AS degree FROM');
            $memberships = str_contains($sql, 'SELECT bm.node_id, b.id FROM boundary_memberships bm');
            if (!$degrees && !$memberships) {
                continue;
            }
            ++$planned;
            foreach (RecordingStatement::plan($pdo, $sql, $params) as $detail) {
                $detail = (string) $detail;
                $table = preg_match('/^SEARCH (edges|bm)\b/', $detail) === 1;
                if (($table && !str_contains($detail, 'COVERING INDEX')) || ($memberships && str_contains($detail, 'TEMP B-TREE'))) {
                    $rowReads[] = $detail . '  <=  ' . substr($sql, 0, 120);
                }
            }
        }

        // The slice's degrees, its memberships, and the brief's ranking.
        assertNotSame(0, $planned, 'No hub ranking statement ran, so this test checked nothing.');
        assertSame(3, $planned);
        assertSame([], $rowReads);
    }
}
