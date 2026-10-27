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
 * The dead-code candidate search reads a node's edges by seeking its source
 * or target once and filtering the kinds row by row.
 *
 * Seeking (project, target, kind) instead made SQLite probe the index once per
 * selected kind, 23 of them by default, for every node of the project: the
 * test-only search alone took 1.5 s over 50,000 nodes, and the whole search
 * ran past its 5 s budget on SQLite 3.54.
 */
final class DeadCodeCandidatePlanTest extends KnossosTestCase
{
    #[Group('query')]
    public function testTheCandidateSearchSeeksAnEdgeEndpointOnceRatherThanOncePerKind(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $project = $ids['project'];
        for ($index = 0; $index < 3; ++$index) {
            $class = sprintf('App\\Unused%d', $index);
            $classId = StableId::symbol($project, 'php', 'class', $class);
            $methodId = StableId::symbol($project, 'php', 'method', $class . '::run');
            $repository->saveNode($classId, $project, 'php', 'class', $class, sprintf('Unused%d', $index), null, $ids['file'], 1, 9, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
            $repository->saveNode($methodId, $project, 'php', 'method', $class . '::run', 'run', null, $ids['file'], 2, 8, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
            $repository->saveEdge(StableId::edge($project, 'contains', $classId, $methodId, (string) $index), $project, 'contains', $classId, $methodId, $ids['file'], 2, 2, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        }
        $repository->completeScan($project, $ids['scan']);

        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [RecordingStatement::class]);
        RecordingStatement::drain();
        try {
            ArchitectureQueryService::forDatabase($pdo)->services()->healthQueries->architectureHealth($project, limit: 10);
        } finally {
            $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PDOStatement::class]);
        }

        $perKindSeeks = [];
        $edgeQueries = 0;
        foreach (RecordingStatement::drain() as [$sql, $params]) {
            // The edge reads filtered by the selected kinds, 23 by default. A
            // read of one kind (`contains`), or of a fixed two or three, costs
            // as many probes as kinds and is left to the planner.
            if (!str_contains($sql, 'FROM edges') || !preg_match('/kind IN \(\?(,\?){4,}\)/', $sql)) {
                continue;
            }
            ++$edgeQueries;
            foreach (RecordingStatement::plan($pdo, $sql, $params) as $detail) {
                // A member walk seeks the one kind it names, `contains`, which costs a single probe.
                $containsWalk = str_contains((string) $detail, 'edges_project_source_idx') && str_contains($sql, "kind = 'contains'");
                if (preg_match('/edges_project_(target|source)_idx \(project_id=\? AND (target|source)_id=\? AND kind=\?\)/', (string) $detail) && !$containsWalk) {
                    $perKindSeeks[] = $detail . '  <=  ' . substr($sql, 0, 120);
                }
            }
        }

        assertNotSame(0, $edgeQueries, 'The search read no edges by endpoint, so this test checked nothing.');
        assertSame([], $perKindSeeks);
    }
}
