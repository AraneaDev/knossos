<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\DashboardService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

use function PHPUnit\Framework\assertArrayNotHasKey;
use function PHPUnit\Framework\assertNotContains;
use function PHPUnit\Framework\assertSame;

/**
 * A snapshot past the rows the trend reads a snapshot whole within costs the
 * trend that one point, said with the table, its rows and the limit; the
 * rest of the trend, and the dashboard around it, still answer.
 */
final class TrendRowLimitTest extends KnossosTestCase
{
    #[Group('query')]
    public function testAnActiveSnapshotPastTheRowLimitLosesItsTrendPointNotTheDashboard(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('turn-brief');
        try {
            $scan = (string) $pdo->query("SELECT active_scan_id FROM projects WHERE id = '{$projectId}'")->fetchColumn();
            $pdo->prepare(
                "WITH RECURSIVE n(x) AS (SELECT 1 UNION ALL SELECT x + 1 FROM n WHERE x < 200001) "
                . "INSERT INTO nodes (id, project_id, language, kind, canonical_name, display_name, origin, confidence, owner_key, last_scan_id) "
                . "SELECT 'bulk-' || x, :project, 'php', 'function', 'bulk' || x, 'bulk' || x, 'ast', 'certain', 'bulk', :scan FROM n",
            )->execute(['project' => $projectId, 'scan' => $scan]);
            $rows = (int) $pdo->query("SELECT COUNT(*) FROM nodes WHERE project_id = '{$projectId}'")->fetchColumn();

            $series = ArchitectureQueryService::forDatabase($pdo)->architectureTrends($projectId, 2)->data['series'];
            $active = array_values(array_filter($series, static fn(array $p): bool => ($p['active'] ?? false) === true))[0];
            assertSame(false, $active['complete']);
            assertSame(['code' => 'row_limit', 'table' => 'nodes', 'rows' => $rows, 'limit' => 200000], $active['reason']);
            assertArrayNotHasKey('metrics', $active);

            $dashboard = (new DashboardService($pdo))->dashboard($root);
            assertSame('ok', $dashboard['status']);
            assertNotContains($scan, array_column($dashboard['trend'], 'snapshot_id'));
            assertSame(['code' => 'row_limit', 'table' => 'nodes', 'rows' => $rows, 'limit' => 200000], $dashboard['trend_limit']);
        } finally {
            $this->removeTempTree($root);
        }
    }
}
