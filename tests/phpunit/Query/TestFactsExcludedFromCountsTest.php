<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\DashboardService;
use Knossos\Scan\ProjectScanService;
use Knossos\Store\SnapshotFacts;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;

use function PHPUnit\Framework\assertGreaterThan;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * Test doubles and test cases are facts about the tests, not the architecture:
 * the health counts, the dead-code candidates, the dashboard's summary and the
 * agent brief of the Momus fixture read the same whether the test facts its
 * scan derived are in the graph or taken out of it.
 *
 * Tests that call production code do move these figures (they make it
 * reachable), so the comparison is of one scan with and without the derived
 * rows, not of two scans.
 */
#[Group('test-fidelity')]
#[Group('typescript-scanner')]
final class TestFactsExcludedFromCountsTest extends KnossosTestCase
{
    private const MOMUS = '/tests/Fixtures/test-fidelity/typescript/momus';

    public function testNoTestFactMovesAnyCount(): void
    {
        $root = self::scratchPath('counts-with-test-facts');
        $this->copyTree(self::repositoryRoot() . self::MOMUS, $root);
        $pdo = $this->freshTestDatabase();
        (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);

        $with = self::figures($pdo, $root);
        $kinds = "'" . implode("', '", SnapshotFacts::DERIVED_NODE_KINDS) . "'";
        assertGreaterThan(0, (int) $pdo->query("SELECT COUNT(*) FROM nodes WHERE kind = 'test_double'")->fetchColumn());
        assertGreaterThan(0, (int) $pdo->query("SELECT COUNT(*) FROM edges WHERE kind = 'doubles'")->fetchColumn());

        $pdo->exec('DELETE FROM test_findings');
        $pdo->exec("DELETE FROM edges WHERE kind = 'doubles' OR source_id IN (SELECT id FROM nodes WHERE kind IN ({$kinds})) OR target_id IN (SELECT id FROM nodes WHERE kind IN ({$kinds}))");
        $pdo->exec("DELETE FROM classifications WHERE node_id IN (SELECT id FROM nodes WHERE kind IN ({$kinds}))");
        $pdo->exec("DELETE FROM boundary_memberships WHERE node_id IN (SELECT id FROM nodes WHERE kind IN ({$kinds}))");
        $pdo->exec("DELETE FROM nodes WHERE kind IN ({$kinds})");
        $without = self::figures($pdo, $root);

        assertSame($without, $with);
        foreach ($with['kinds'] as $kind) {
            self::assertNotContains($kind['kind'], SnapshotFacts::DERIVED_NODE_KINDS);
        }
        self::assertStringNotContainsString('test_double', json_encode($with, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{kinds: list<array{kind: string, count: int}>, boundaries: mixed, candidates: int, probable: int, hubs: list<string>, hotspots: list<string>, brief: string}
     */
    private static function figures(PDO $pdo, string $root): array
    {
        $dashboard = (new DashboardService($pdo))->dashboard($root);
        $services = ArchitectureQueryService::forDatabase($pdo)->services();
        $projectId = (string) $dashboard['project_id'];
        $health = $services->healthQueries->architectureHealth($projectId, limit: 50, includeTests: true)->data;
        $brief = $services->briefQueries->exportAgentBrief($projectId, 5000)->data['markdown'];

        return [
            'kinds' => $dashboard['summary']['kinds'],
            'boundaries' => $dashboard['boundaries'],
            'candidates' => $health['bounds']['candidates_total'],
            'probable' => $health['bounds']['candidates_probable'],
            'hubs' => array_column(array_column($health['hubs'], 'component'), 'canonical_name'),
            'hotspots' => array_column(array_column($health['static_hotspots'], 'component'), 'canonical_name'),
            // The generated-at date and the project's name differ by nothing here, so the brief is compared whole.
            'brief' => (string) preg_replace('/scan of \S+:/', 'scan of X:', $brief),
        ];
    }
}
