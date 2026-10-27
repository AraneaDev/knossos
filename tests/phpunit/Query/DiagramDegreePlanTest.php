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
 * A diagram ranks its candidates by degree counted from the endpoint indexes
 * alone when every confidence is admitted and the selected kinds are most of
 * the project's edges.
 *
 * The confidence test, which every edge passes at the lowest rank, read each
 * edge's row to check it: 540 ms on a scan of this repository, against 390 ms
 * from the indexes. Where the selected kinds are few, the kind index reaches
 * them without walking every edge, and is kept.
 */
final class DiagramDegreePlanTest extends KnossosTestCase
{
    #[Group('query')]
    public function testABroadSelectionIsCountedFromTheIndexesAlone(): void
    {
        $rowReads = [];
        foreach ($this->degreePlans('calls') as $detail) {
            if (str_starts_with($detail, 'SEARCH edges') && !str_contains($detail, 'COVERING INDEX')) {
                $rowReads[] = $detail;
            }
        }

        assertSame([], $rowReads);
    }

    #[Group('query')]
    public function testASparseSelectionKeepsTheKindIndex(): void
    {
        $byKind = array_filter($this->degreePlans('contains'), static fn(string $detail): bool => str_contains($detail, 'edges_project_kind_idx'));

        assertNotSame([], $byKind);
    }

    /**
     * The plan lines of the degree counts a diagram makes over 3,540 edges of
     * `$bulk` between 60 functions and one call.
     *
     * @return list<string>
     */
    private function degreePlans(string $bulk): array
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $project = $ids['project'];
        $functions = [];
        for ($index = 0; $index < 60; ++$index) {
            $name = sprintf('App\\step%d', $index);
            $functions[] = $id = StableId::symbol($project, 'php', 'function', $name);
            $repository->saveNode($id, $project, 'php', 'function', $name, sprintf('step%d', $index), null, $ids['file'], 1, 9, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        }
        foreach ($functions as $from) {
            foreach ($functions as $to) {
                if ($from !== $to) {
                    $repository->saveEdge(StableId::edge($project, $bulk, $from, $to, '1'), $project, $bulk, $from, $to, $ids['file'], 2, 2, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
                }
            }
        }
        $repository->completeScan($project, $ids['scan']);

        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [RecordingStatement::class]);
        RecordingStatement::drain();
        try {
            ArchitectureQueryService::forDatabase($pdo)->services()->diagramQueries->exportDiagram($project);
        } finally {
            $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PDOStatement::class]);
        }
        $plans = [];
        foreach (RecordingStatement::drain() as [$sql, $params]) {
            if (str_contains($sql, 'COUNT(*) AS degree')) {
                array_push($plans, ...RecordingStatement::plan($pdo, $sql, $params));
            }
        }
        assertNotSame([], $plans, 'No degree was counted, so this test checked nothing.');

        return $plans;
    }
}
