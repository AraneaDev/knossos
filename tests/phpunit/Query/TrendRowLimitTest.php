<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\DashboardService;
use Knossos\Store\SnapshotBounds;
use Knossos\Store\SnapshotPayload;
use Knossos\Store\SqliteValues;
use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertArrayNotHasKey;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

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
                'WITH RECURSIVE n(x) AS (SELECT 1 UNION ALL SELECT x + 1 FROM n WHERE x < ' . (SnapshotBounds::MAX_ROWS_PER_TABLE + 1) . ') '
                . 'INSERT INTO nodes (id, project_id, language, kind, canonical_name, display_name, origin, confidence, owner_key, last_scan_id) '
                . "SELECT 'bulk-' || x, :project, 'php', 'function', 'bulk' || x, 'bulk' || x, 'ast', 'certain', 'bulk', :scan FROM n",
            )->execute(['project' => $projectId, 'scan' => $scan]);
            $rows = (int) $pdo->query("SELECT COUNT(*) FROM nodes WHERE project_id = '{$projectId}'")->fetchColumn();

            $series = ArchitectureQueryService::forDatabase($pdo)->services()->gateQueries->architectureTrends($projectId, 2)->data['series'];
            $active = array_values(array_filter($series, static fn(array $p): bool => ($p['active'] ?? false) === true))[0];
            assertSame([false, true], [$active['complete'], $active['partial']]);
            assertSame(['code' => 'row_limit', 'table' => 'nodes', 'rows' => $rows, 'limit' => SnapshotBounds::MAX_ROWS_PER_TABLE], $active['reason']);
            // What COUNT(*) says without reading the rows; what needs the graph itself is not counted.
            assertSame($rows, $active['counts']['components']);
            assertSame(['cycles' => null, 'max_degree' => null, 'error_diagnostics' => 0, 'warning_diagnostics' => 0, 'unreferenced_candidates' => null], $active['metrics']);

            $dashboard = (new DashboardService($pdo))->dashboard($root);
            assertSame('ok', $dashboard['status']);
            $point = array_values(array_filter($dashboard['trend'], static fn(array $p): bool => $p['snapshot_id'] === $scan))[0];
            assertSame(['cycles' => null, 'max_degree' => null, 'dead_code' => null, 'diagnostics' => 0, 'components' => $rows], array_intersect_key($point, array_flip(['cycles', 'max_degree', 'dead_code', 'diagnostics', 'components'])));
            assertSame(['code' => 'row_limit', 'table' => 'nodes', 'rows' => $rows, 'limit' => SnapshotBounds::MAX_ROWS_PER_TABLE], $dashboard['trend_limit']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * Retains an older snapshot of the project, stored as `$stub` the way the archive stores one past a limit.
     *
     * @param array<string, mixed> $stub
     */
    private static function retainStub(\PDO $pdo, string $projectId, string $scanId, array $stub): void
    {
        $at = '2026-01-01T00:00:00Z';
        $pdo->prepare("INSERT INTO scans (id, project_id, mode, status, scanner_set_hash, started_at, finished_at) VALUES (:id, :project, 'full', 'complete', 'h', :at, :at)")
            ->execute(['id' => $scanId, 'project' => $projectId, 'at' => $at]);
        $json = SqliteValues::json($stub);
        $pdo->prepare('INSERT INTO scan_snapshots (scan_id, project_id, scanner_set_hash, config_hash, complete, fact_count, byte_size, payload_json, captured_at) VALUES (:scan, :project, :h, :c, 0, 0, :bytes, :payload, :at)')
            ->execute(['scan' => $scanId, 'project' => $projectId, 'h' => 'h', 'c' => 'c', 'bytes' => strlen($json), 'payload' => SnapshotPayload::encode($json), 'at' => $at]);
    }

    #[Group('query')]
    public function testAnArchivedSnapshotPastTheRowCapIsAPartialPointWithItsCountedFigures(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('turn-brief');
        try {
            $counts = ['components' => 250000, 'relationships' => 400000, 'roles' => 10, 'boundaries' => 3, 'diagnostics' => 7];
            self::retainStub($pdo, $projectId, 'scan_old', ['schema' => 1, 'reason' => 'fact_limit']);
            self::retainStub($pdo, $projectId, 'scan_big', ['schema' => 1, 'reason' => 'fact_limit', 'figures' => ['counts' => $counts, 'metrics' => ['error_diagnostics' => 2, 'warning_diagnostics' => 5]]]);

            $series = ArchitectureQueryService::forDatabase($pdo)->services()->gateQueries->architectureTrends($projectId, 5)->data['series'];
            $byId = array_column($series, null, 'scan_id');
            assertSame([false, true, 'fact_limit'], [$byId['scan_big']['complete'], $byId['scan_big']['partial'], $byId['scan_big']['reason']]);
            assertSame($counts, $byId['scan_big']['counts']);
            assertSame(['cycles' => null, 'max_degree' => null, 'error_diagnostics' => 2, 'warning_diagnostics' => 5, 'unreferenced_candidates' => null], $byId['scan_big']['metrics']);
            // A stub from before figures were kept says only that it is incomplete, as it always did.
            assertSame(false, $byId['scan_old']['complete']);
            assertArrayNotHasKey('metrics', $byId['scan_old']);

            // A delta never subtracts a figure that was not counted.
            $deltas = (new DashboardService($pdo))->dashboard($root)['deltas'];
            assertSame('scan_big', $deltas['against']);
            assertNull($deltas['cycles']);
            assertNull($deltas['max_degree']);
            assertNull($deltas['dead_code']);
        } finally {
            $this->removeTempTree($root);
        }
    }
}
