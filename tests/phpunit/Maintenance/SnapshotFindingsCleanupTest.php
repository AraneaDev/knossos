<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Maintenance;

use Knossos\Maintenance\DatabaseMaintenanceService;
use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/** A removed scan takes the findings its snapshot kept with it, also on a connection that does not enforce foreign keys. */
#[Group('database-maintenance-service')]
final class SnapshotFindingsCleanupTest extends KnossosTestCase
{
    public function testCleanupStaleScansExecuteDeletesTheFindingsOfARemovedScan(): void
    {
        $pdo = $this->freshTestDatabase();
        $pdo->exec("INSERT INTO projects(id, name, root_realpath, config_json, created_at, updated_at) VALUES ('proj-1', 'Test', '/nowhere/proj-1', '{}', '2000-01-01T00:00:00Z', '2000-01-01T00:00:00Z')");
        $pdo->exec("INSERT INTO scans(id, project_id, mode, status, scanner_set_hash, started_at) VALUES ('old', 'proj-1', 'full', 'failed', 'hash', '2000-01-01T00:00:00Z')");
        $pdo->exec("INSERT INTO scan_snapshots(scan_id, project_id, scanner_set_hash, config_hash, complete, fact_count, byte_size, payload_json, captured_at, test_findings_recorded) VALUES ('old', 'proj-1', 'h', 'c', 1, 0, 2, '{}', '2000-01-01T00:00:00Z', 1)");
        $pdo->exec("INSERT INTO snapshot_test_findings(project_id, scan_id, key, rule_id, category, sub_rule, confidence, file, target, message) VALUES ('proj-1', 'old', 'k', 'r', 'GHOST_MEMBER', 's', 'definite', NULL, NULL, 'm')");
        $pdo->exec('PRAGMA foreign_keys = OFF');

        (new DatabaseMaintenanceService($pdo, ':memory:'))->cleanupStaleScans('proj-1', 24, true);

        assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM snapshot_test_findings WHERE scan_id = 'old'")->fetchColumn());
    }
}
