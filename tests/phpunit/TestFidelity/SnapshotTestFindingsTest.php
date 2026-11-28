<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Scan\ProjectScanService;
use Knossos\TestFidelity\SnapshotTestFindings;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;

use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * A snapshot keeps the test findings its scan judged, by key, so a gate can
 * compare the findings of today with those of a baseline. A snapshot made
 * before they were kept has no record, which is not the same as no findings.
 */
#[Group('test-fidelity')]
#[Group('typescript-scanner')]
final class SnapshotTestFindingsTest extends KnossosTestCase
{
    private const FIXTURE = '/tests/Fixtures/test-fidelity/ts-audit';

    public function testASnapshotKeepsItsFindingsByKey(): void
    {
        [$pdo, $service, $root] = $this->scanned();
        $first = $service->scan($root);
        $active = ArchitectureQueryService::forDatabase($pdo)->services()->testFidelityQueries->auditTests($first->projectId, limit: 200)->data['items'];
        assertNotSame([], $active);
        $this->touchTheSource($root);
        $service->scan($root);

        $kept = (new SnapshotTestFindings($pdo))->forSnapshot($first->projectId, (string) $first->snapshotId);

        $canonical = $pdo->prepare('SELECT t.canonical_name FROM test_findings tf LEFT JOIN nodes t ON t.id = tf.target_node_id WHERE tf.id = ?');
        $expected = array_map(static function (array $item) use ($canonical): array {
            $canonical->execute([$item['id']]);
            $target = $canonical->fetchColumn();

            return [
                'key' => $item['key'], 'rule_id' => $item['rule_id'], 'category' => $item['category'], 'sub_rule' => $item['sub_rule'],
                'confidence' => $item['confidence'], 'file' => $item['file'], 'target' => $target === false ? null : $target, 'message' => $item['message'],
            ];
        }, $active);
        usort($expected, static fn(array $a, array $b): int => strcmp($a['key'], $b['key']));
        assertSame($expected, $kept);
    }

    public function testTwoTargetsWithOneDisplayNameKeepTwoKeysAndTheLiveKeyIsTheArchivedKey(): void
    {
        $root = self::scratchPath('ts-snapshot-same-display-name');
        $this->copyTree(self::repositoryRoot() . self::FIXTURE, $root);
        self::removeFolder($root . '/tests');
        mkdir($root . '/src/a', 0o777, true);
        mkdir($root . '/src/b', 0o777, true);
        foreach (['a', 'b'] as $folder) {
            file_put_contents($root . '/src/' . $folder . '/Gateway.ts', "export class Gateway {\n    charge(): number { return 1; }\n}\n");
        }
        mkdir($root . '/tests', 0o777, true);
        file_put_contents($root . '/tests/gateways.test.ts', <<<'TS'
            import { it, vi } from 'vitest';
            import { Gateway as First } from '../src/a/Gateway';
            import { Gateway as Second } from '../src/b/Gateway';
            it('stubs what both gateways no longer have', () => {
                vi.spyOn(new First(), 'chargeOld');
                vi.spyOn(new Second(), 'chargeOld');
            });
            TS);
        $pdo = $this->freshTestDatabase();
        $service = new ProjectScanService($pdo, self::repositoryRoot(), [$root]);
        $first = $service->scan($root);
        $live = ArchitectureQueryService::forDatabase($pdo)->services()->testFidelityQueries->auditTests($first->projectId, limit: 200)->data['items'];
        $this->touchTheSource($root);
        $service->scan($root);

        $kept = (new SnapshotTestFindings($pdo))->forSnapshot($first->projectId, (string) $first->snapshotId);

        $keys = array_column($live, 'key');
        assertSame(2, count($keys));
        assertSame(2, count(array_unique($keys)));
        assertSame(['Gateway', 'Gateway'], array_column($live, 'target'));
        $archivedKeys = array_column((array) $kept, 'key');
        sort($keys);
        assertSame($keys, $archivedKeys);
        assertSame(2, count(array_unique(array_column((array) $kept, 'target'))));
    }

    public function testASnapshotFromBeforeTheMigrationHasNoRecord(): void
    {
        [$pdo, $service, $root] = $this->scanned();
        $first = $service->scan($root);
        $this->touchTheSource($root);
        $service->scan($root);
        $reader = new SnapshotTestFindings($pdo);
        assertNotSame(null, $reader->forSnapshot($first->projectId, (string) $first->snapshotId));

        $pdo->exec('UPDATE scan_snapshots SET test_findings_recorded = 0');
        $pdo->exec('DELETE FROM snapshot_test_findings');

        assertNull($reader->forSnapshot($first->projectId, (string) $first->snapshotId));
        assertNull($reader->forSnapshot($first->projectId, 'no-such-scan'));
        assertNull($reader->forSnapshot('no-such-project', (string) $first->snapshotId));
    }

    public function testACleanSnapshotRecordsEmpty(): void
    {
        [$pdo, $service, $root] = $this->scanned();
        self::removeFolder($root . '/tests');
        $first = $service->scan($root);
        $this->touchTheSource($root);
        $service->scan($root);

        assertSame([], (new SnapshotTestFindings($pdo))->forSnapshot($first->projectId, (string) $first->snapshotId));
    }

    #[Group('slow')]
    public function testPruningASnapshotDropsItsFindings(): void
    {
        foreach ([true, false] as $enforced) {
            [$pdo, $service, $root] = $this->scanned();
            file_put_contents($root . '/knossos.json', json_encode(['version' => 1, 'snapshot_retention' => 1]));
            $pdo->exec('PRAGMA foreign_keys = ' . ($enforced ? 'ON' : 'OFF'));
            $first = $service->scan($root);
            $this->touchTheSource($root);
            $second = $service->scan($root);
            $this->touchTheSource($root, 'second');
            $third = $service->scan($root);

            $scans = $pdo->query('SELECT scan_id FROM snapshot_test_findings GROUP BY scan_id')->fetchAll(PDO::FETCH_COLUMN);
            assertSame([(string) $second->snapshotId], $scans, 'foreign keys ' . ($enforced ? 'on' : 'off'));
            assertNull((new SnapshotTestFindings($pdo))->forSnapshot($first->projectId, (string) $first->snapshotId));
            assertNotSame($third->snapshotId, $second->snapshotId);
        }
    }

    /** @return array{0: PDO, 1: ProjectScanService, 2: string} */
    private function scanned(): array
    {
        $root = self::scratchPath('ts-snapshot-test-findings');
        $this->copyTree(self::repositoryRoot() . self::FIXTURE, $root);
        $pdo = $this->freshTestDatabase();

        return [$pdo, new ProjectScanService($pdo, self::repositoryRoot(), [$root]), $root];
    }

    /** A change to production code, so the next scan rebuilds the graph and archives the one before. */
    private function touchTheSource(string $root, string $marker = 'first'): void
    {
        file_put_contents($root . '/src/Extra' . ucfirst($marker) . '.ts', "export const {$marker} = 1;\n");
    }

    private static function removeFolder(string $path): void
    {
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            is_dir($path . '/' . $entry) ? self::removeFolder($path . '/' . $entry) : unlink($path . '/' . $entry);
        }
        rmdir($path);
    }
}
