<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Reconciliation;

use Knossos\Discovery\DiscoveredFile;
use Knossos\Discovery\DiscoveryResult;
use Knossos\Reconciliation\FullScanRequest;
use Knossos\Reconciliation\GraphReconciler;
use Knossos\Reconciliation\ReconciliationDiagnostics;
use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\EdgeFact;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Scanner\Protocol\ScannerManifest;
use Knossos\Store\MigrationRunner;
use Knossos\Store\SqliteConnection;
use Knossos\Store\SqliteGraphRepository;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

final class EndpointRouteReconcileTest extends KnossosTestCase
{
    private PDO $pdo;

    private SqliteGraphRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = SqliteConnection::open(':memory:');
        (new MigrationRunner($this->pdo, self::repositoryRoot() . '/migrations'))->migrate();
        $this->repository = new SqliteGraphRepository($this->pdo);
    }

    #[Group('reconciliation')]
    public function testOneUrlCalledFromTwoFilesIsOneEndpointWithoutADuplicateWarning(): void
    {
        $contributions = [];
        foreach (['src/a.ts', 'src/b.ts'] as $path) {
            $evidence = new Evidence($path, 2, 2);
            $contributions[] = new ScanContribution(
                'knossos.typescript:file:' . $path,
                [
                    new NodeFact('ts:module:' . $path, 'module', $path, $path, Origin::Ast, Confidence::Certain, $evidence),
                    new NodeFact('ts:endpoint:GET /api/orders', 'endpoint', 'GET /api/orders', 'GET /api/orders', Origin::Ast, Confidence::Certain, $evidence),
                ],
                [new EdgeFact('calls_endpoint', 'ts:module:' . $path, 'ts:endpoint:GET /api/orders', Origin::Ast, Confidence::Certain, $evidence)],
                [],
            );
        }

        $this->reconcile($contributions, ['src/a.ts', 'src/b.ts']);

        $codes = $this->pdo->query('SELECT code FROM diagnostics')->fetchAll(PDO::FETCH_COLUMN);
        assertSame(false, in_array('reconciler.duplicate_symbol_evidence', $codes, true));
        assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM nodes WHERE kind = 'endpoint'")->fetchColumn());
        assertSame(2, (int) $this->pdo->query("SELECT COUNT(*) FROM edges WHERE kind = 'calls_endpoint'")->fetchColumn());
    }

    #[Group('reconciliation')]
    public function testAReconcilerWarningKeepsItsSeverityAndLines(): void
    {
        $contribution = new ScanContribution(
            'knossos.typescript:file:src/a.ts',
            [new NodeFact('ts:module:src/a.ts', 'module', 'src/a.ts', 'src/a.ts', Origin::Ast, Confidence::Certain, new Evidence('src/a.ts', 1, 1))],
            [],
            [],
        );
        $this->reconcile([$contribution], ['src/a.ts']);
        $projectId = (string) $this->pdo->query('SELECT id FROM projects')->fetchColumn();
        $scanId = (string) $this->pdo->query('SELECT id FROM scans')->fetchColumn();
        $fileId = (string) $this->pdo->query("SELECT id FROM files WHERE relative_path = 'src/a.ts'")->fetchColumn();

        $request = new FullScanRequest('p', 'P', $this->discovery(['src/a.ts']), [], []);
        (new ReconciliationDiagnostics($this->repository))->save($request, $projectId, $scanId, ['src/a.ts' => $fileId], [
            ['owner' => 'o', 'code' => 'X', 'message' => 'm', 'path' => 'src/a.ts', 'severity' => 'info', 'start_line' => 3, 'end_line' => 4],
            ['owner' => 'o', 'code' => 'Y', 'message' => 'm', 'path' => 'src/a.ts'],
        ]);

        $rows = $this->pdo->query("SELECT code, severity, start_line, end_line FROM diagnostics WHERE code IN ('X', 'Y') ORDER BY code")->fetchAll(PDO::FETCH_ASSOC);
        assertSame([
            ['code' => 'X', 'severity' => 'info', 'start_line' => 3, 'end_line' => 4],
            ['code' => 'Y', 'severity' => 'warning', 'start_line' => null, 'end_line' => null],
        ], $rows);
    }

    /**
     * @param list<ScanContribution> $contributions
     * @param list<string> $paths
     */
    private function reconcile(array $contributions, array $paths): void
    {
        $scanners = [new ScannerManifest('knossos.typescript', '0.1.0', '1.0', '1.0', ['typescript'], ['ts'], [])];
        (new GraphReconciler($this->repository))->reconcile(
            new FullScanRequest('endpoint-route', 'Endpoint Route', $this->discovery($paths), $scanners, $contributions),
        );
    }

    /** @param list<string> $paths */
    private function discovery(array $paths): DiscoveryResult
    {
        return new DiscoveryResult(
            '/workspace/endpoint-route',
            array_map(
                static fn(string $path): DiscoveredFile => new DiscoveredFile($path, '/workspace/endpoint-route/' . $path, 'typescript', 30, 1_000, hash('sha256', $path)),
                $paths,
            ),
            [],
            [],
            hash('sha256', 'inputs'),
            hash('sha256', 'config'),
        );
    }
}
