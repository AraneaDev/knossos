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
    public const ORDERS_PATHS = ['src/app.ts', 'routes/web.php', 'app/OrdersController.php'];

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

    #[Group('reconciliation')]
    public function testACallerIsJoinedToTheRouteThatServesItsUrl(): void
    {
        $this->reconcile(self::ordersContributions(), self::ORDERS_PATHS);

        $rows = $this->pdo->query("SELECT origin, confidence, attributes_json FROM edges WHERE kind = 'resolves_to'")->fetchAll(PDO::FETCH_ASSOC);
        assertSame(1, count($rows));
        assertSame('derived', $rows[0]['origin']);
        assertSame('probable', $rows[0]['confidence']);
        $attributes = json_decode((string) $rows[0]['attributes_json'], true);
        assertSame('parameter', $attributes['match']);
    }

    /**
     * A TypeScript module calling GET /api/orders/42 and a Laravel route
     * serving GET api/orders/{id}, which a controller method handles.
     *
     * @return list<ScanContribution>
     */
    public static function ordersContributions(): array
    {
        $call = new Evidence('src/app.ts', 7, 7);
        $route = new Evidence('routes/web.php', 3, 3);
        $handler = new Evidence('app/OrdersController.php', 5, 9);

        return [
            new ScanContribution(
                'knossos.typescript:file:src/app.ts',
                [
                    new NodeFact('ts:module:src/app.ts', 'module', 'src/app.ts', 'src/app.ts', Origin::Ast, Confidence::Certain, $call),
                    new NodeFact('ts:endpoint:GET /api/orders/42', 'endpoint', 'GET /api/orders/42', 'GET /api/orders/42', Origin::Ast, Confidence::Certain, $call, [
                        'method' => 'GET', 'uri' => '/api/orders/42', 'template' => '/api/orders/42', 'prefix' => 'known',
                    ]),
                ],
                [new EdgeFact('calls_endpoint', 'ts:module:src/app.ts', 'ts:endpoint:GET /api/orders/42', Origin::Ast, Confidence::Certain, $call)],
                [],
            ),
            new ScanContribution(
                'knossos.php:file:routes/web.php',
                [new NodeFact('php:route:GET api/orders/{id}', 'route', 'GET api/orders/{id}', 'GET api/orders/{id}', Origin::FrameworkConvention, Confidence::Certain, $route, [
                    'framework' => 'laravel', 'methods' => ['GET'], 'uri' => 'api/orders/{id}',
                ])],
                [new EdgeFact('routes_to', 'php:route:GET api/orders/{id}', 'php:method:App\\OrdersController::show', Origin::FrameworkConvention, Confidence::Certain, $route)],
                [],
            ),
            new ScanContribution(
                'knossos.php:file:app/OrdersController.php',
                [new NodeFact('php:method:App\\OrdersController::show', 'method', 'App\\OrdersController::show', 'show', Origin::Ast, Confidence::Certain, $handler)],
                [],
                [],
            ),
        ];
    }

    /**
     * @param list<ScanContribution> $contributions
     * @param list<string> $paths
     */
    private function reconcile(array $contributions, array $paths): void
    {
        self::reconcileInto($this->repository, $contributions, $paths);
    }

    /**
     * @param list<ScanContribution> $contributions
     * @param list<string> $paths
     */
    public static function reconcileInto(SqliteGraphRepository $repository, array $contributions, array $paths): void
    {
        $scanners = [
            new ScannerManifest('knossos.typescript', '0.1.0', '1.0', '1.0', ['typescript'], ['ts'], []),
            new ScannerManifest('knossos.php', '0.1.0', '1.0', '1.0', ['php'], ['php'], []),
        ];
        (new GraphReconciler($repository))->reconcile(
            new FullScanRequest('endpoint-route', 'Endpoint Route', self::discoveryOf($paths), $scanners, $contributions),
        );
    }

    /** @param list<string> $paths */
    private function discovery(array $paths): DiscoveryResult
    {
        return self::discoveryOf($paths);
    }

    /** @param list<string> $paths */
    private static function discoveryOf(array $paths): DiscoveryResult
    {
        return new DiscoveryResult(
            '/workspace/endpoint-route',
            array_map(
                static fn(string $path): DiscoveredFile => new DiscoveredFile($path, '/workspace/endpoint-route/' . $path, str_ends_with($path, '.php') ? 'php' : 'typescript', 30, 1_000, hash('sha256', $path)),
                $paths,
            ),
            [],
            [],
            hash('sha256', 'inputs'),
            hash('sha256', 'config'),
        );
    }
}
