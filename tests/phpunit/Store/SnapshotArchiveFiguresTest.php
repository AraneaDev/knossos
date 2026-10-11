<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Store;

use Knossos\Store\SnapshotPayload;
use Knossos\Store\SqliteScanLifecycle;
use Knossos\Store\SqliteSnapshotArchive;
use Knossos\Store\SqliteStatementCache;
use Knossos\Store\SqliteTransactions;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

use function PHPUnit\Framework\assertSame;

/**
 * A snapshot past the row cap keeps no facts, but what SQL can count without
 * reading them is kept beside the reason, so the trend still has a point.
 */
final class SnapshotArchiveFiguresTest extends KnossosTestCase
{
    private const TABLES = ['files', 'nodes', 'edges', 'classifications', 'boundaries', 'boundary_memberships', 'diagnostics'];

    #[Group('store')]
    public function testASnapshotPastTheRowCapKeepsItsCountedFigures(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        foreach ([['d1', 'error'], ['d2', 'warning'], ['d3', 'warning'], ['d4', 'info']] as [$id, $severity]) {
            $repository->saveDiagnostic($id, $ids['project'], $ids['scan'], $ids['file'], $severity, 'X', 'm', 1, 1, 'o');
        }
        $repository->completeScan($ids['project'], $ids['scan']);
        $lifecycle = new SqliteScanLifecycle(new SqliteStatementCache($pdo), new SqliteTransactions($pdo));

        (new SqliteSnapshotArchive($pdo, $lifecycle, self::TABLES, 1))->archiveActiveSnapshot($ids['project'], 'c', 5);

        $row = $pdo->query('SELECT complete, payload_json FROM scan_snapshots')->fetch(PDO::FETCH_ASSOC);
        assertSame(0, (int) $row['complete']);
        $count = static fn(string $table): int => (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
        assertSame([
            'schema' => 1,
            'reason' => 'fact_limit',
            'figures' => [
                'counts' => ['components' => $count('nodes'), 'relationships' => $count('edges'), 'roles' => $count('classifications'), 'boundaries' => $count('boundaries'), 'diagnostics' => 4],
                'metrics' => ['error_diagnostics' => 1, 'warning_diagnostics' => 2],
            ],
        ], json_decode(SnapshotPayload::decode((string) $row['payload_json']), true, flags: JSON_THROW_ON_ERROR));
    }
}
