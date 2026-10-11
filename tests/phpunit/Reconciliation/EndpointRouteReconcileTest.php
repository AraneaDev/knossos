<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Reconciliation;

use Knossos\Reconciliation\FullScanRequest;
use Knossos\Reconciliation\ReconciliationDiagnostics;
use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\EdgeFact;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Store\MigrationRunner;
use Knossos\Store\SqliteConnection;
use Knossos\Store\SqliteGraphRepository;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\Support\EndpointRouteFixtures;
use PDO;
use PHPUnit\Framework\Attributes\Group;

final class EndpointRouteReconcileTest extends KnossosTestCase
{
    use EndpointRouteFixtures;

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

        $request = new FullScanRequest('p', 'P', self::endpointDiscovery(['src/a.ts']), [], []);
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

    #[Group('reconciliation')]
    public function testACallerIsJoinedToTheRouteThatServesItsUrl(): void
    {
        $this->reconcile(self::ordersContributions(), self::ordersPaths());

        $rows = $this->pdo->query("SELECT origin, confidence, attributes_json FROM edges WHERE kind = 'resolves_to'")->fetchAll(PDO::FETCH_ASSOC);
        assertSame(1, count($rows));
        assertSame('derived', $rows[0]['origin']);
        assertSame('probable', $rows[0]['confidence']);
        $attributes = json_decode((string) $rows[0]['attributes_json'], true);
        assertSame('parameter', $attributes['match']);
    }

    #[Group('reconciliation')]
    public function testAnUnmatchedAndAnAmbiguousEndpointPersistTheirDiagnostics(): void
    {
        $contributions = [
            self::callerContribution('src/app.ts', [['GET', '/orders/{}', 4], ['GET', '/nowhere', 9]]),
            self::laravelRoutes('routes/web.php', [['orders/{id}', 1], ['orders/{order}', 2]]),
        ];

        $this->reconcile($contributions, ['src/app.ts', 'routes/web.php']);

        $fileId = (string) $this->pdo->query("SELECT id FROM files WHERE relative_path = 'src/app.ts'")->fetchColumn();
        $rows = $this->pdo->query("SELECT code, severity, file_id, start_line, end_line FROM diagnostics WHERE code LIKE 'ENDPOINT_ROUTE_%' ORDER BY code")->fetchAll(PDO::FETCH_ASSOC);
        assertSame([
            ['code' => 'ENDPOINT_ROUTE_AMBIGUOUS', 'severity' => 'warning', 'file_id' => $fileId, 'start_line' => 4, 'end_line' => 4],
            ['code' => 'ENDPOINT_ROUTE_UNMATCHED', 'severity' => 'info', 'file_id' => $fileId, 'start_line' => 9, 'end_line' => 9],
        ], $rows);
    }

    #[Group('reconciliation')]
    public function testALaravelApiRouteIsJoinedUnderItsConventionalPrefix(): void
    {
        $contributions = [
            self::callerContribution('src/app.ts', [['GET', '/api/orders', 4]]),
            self::laravelRoutes('routes/api.php', [['orders', 1]]),
        ];

        $this->reconcile($contributions, ['src/app.ts', 'routes/api.php']);

        $rows = $this->pdo->query("SELECT confidence, attributes_json FROM edges WHERE kind = 'resolves_to'")->fetchAll(PDO::FETCH_ASSOC);
        assertSame(1, count($rows));
        assertSame('probable', $rows[0]['confidence']);
        assertSame('exact', json_decode((string) $rows[0]['attributes_json'], true)['match']);
    }

    /**
     * @param list<ScanContribution> $contributions
     * @param list<string> $paths
     */
    private function reconcile(array $contributions, array $paths): void
    {
        self::reconcileEndpointGraph($this->repository, $contributions, $paths);
    }
}
