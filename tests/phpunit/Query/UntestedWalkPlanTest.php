<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\ReportableComponent;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PDOStatement;

use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * untested_code walks out of the tests by seeking each frontier node's edges.
 *
 * Asked for the distinct targets of a chunk of sources, SQLite read the
 * project's whole target index in target order instead: 200 ms a chunk on a
 * scan of this repository, which ran the walk past its five-second limit.
 */
final class UntestedWalkPlanTest extends KnossosTestCase
{
    #[Group('query')]
    public function testTheWalkSeeksTheEdgesOfItsFrontier(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $project = $ids['project'];
        $test = StableId::symbol($project, 'php', 'class', 'App\\Tests\\CheckoutTest');
        $repository->saveNode($test, $project, 'php', 'class', 'App\\Tests\\CheckoutTest', 'CheckoutTest', null, $ids['file'], 20, 30, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        $repository->saveClassification(StableId::classification($project, $test, ReportableComponent::TEST_ROLE, 'test.roles'), $project, $test, ReportableComponent::TEST_ROLE, 'derived', 'probable', 'test.roles', $ids['file'], 20, 30, [], $ids['scan']);
        $repository->saveEdge(StableId::edge($project, 'calls', $test, $ids['checkout'], 'test'), $project, 'calls', $test, $ids['checkout'], $ids['file'], 21, 21, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        $repository->completeScan($project, $ids['scan']);

        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [RecordingStatement::class]);
        RecordingStatement::drain();
        try {
            ArchitectureQueryService::forDatabase($pdo)->services()->untestedQueries->untested($project);
        } finally {
            $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PDOStatement::class]);
        }

        $walks = 0;
        $scans = [];
        foreach (RecordingStatement::drain() as [$sql, $params]) {
            if (!str_contains($sql, 'SELECT DISTINCT target_id FROM edges')) {
                continue;
            }
            ++$walks;
            foreach (RecordingStatement::plan($pdo, $sql, $params) as $detail) {
                // The project alone, or the project and a kind: every edge of the project.
                if (preg_match('/INDEX edges_\w+ \(project_id=\?( AND kind=\?)?\)$/', (string) $detail) === 1) {
                    $scans[] = (string) $detail;
                }
            }
        }

        assertNotSame(0, $walks, 'The walk read no edges, so this test checked nothing.');
        assertSame([], $scans);
    }
}
