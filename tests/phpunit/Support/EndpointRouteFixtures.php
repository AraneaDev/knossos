<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Support;

use Knossos\Discovery\DiscoveredFile;
use Knossos\Discovery\DiscoveryResult;
use Knossos\Reconciliation\FullScanRequest;
use Knossos\Reconciliation\GraphReconciler;
use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\EdgeFact;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Scanner\Protocol\ScannerManifest;
use Knossos\Store\SqliteGraphRepository;

/** Hand-built contributions with callers and routes, and a reconcile of them into a repository. */
trait EndpointRouteFixtures
{
    /**
     * A TypeScript module calling GET /api/orders/42 and a Laravel route
     * serving GET api/orders/{id}, which a controller method handles.
     *
     * @return list<ScanContribution>
     */
    protected static function ordersContributions(): array
    {
        $route = new Evidence('routes/web.php', 3, 3);
        $handler = new Evidence('app/OrdersController.php', 5, 9);

        return [
            self::callerContribution('src/app.ts', [['GET', '/api/orders/42', 7]]),
            new ScanContribution(
                'knossos.php:file:routes/web.php',
                [new NodeFact('php:route:GET api/orders/{id}', 'route', 'GET api/orders/{id}', 'GET api/orders/{id}', Origin::FrameworkConvention, Confidence::Certain, $route, [
                    'framework' => 'laravel', 'methods' => ['GET'], 'path' => 'api/orders/{id}',
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

    /** @return list<string> */
    protected static function ordersPaths(): array
    {
        return ['src/app.ts', 'routes/web.php', 'app/OrdersController.php'];
    }

    /**
     * A TypeScript file whose module calls each [method, url, line].
     *
     * @param list<array{0: string, 1: string, 2: int}> $calls
     */
    protected static function callerContribution(string $path, array $calls): ScanContribution
    {
        $nodes = [new NodeFact('ts:module:' . $path, 'module', $path, $path, Origin::Ast, Confidence::Certain, new Evidence($path, 1, 1))];
        $edges = [];
        foreach ($calls as [$method, $url, $line]) {
            $name = $method . ' ' . $url;
            $evidence = new Evidence($path, $line, $line);
            $nodes[] = new NodeFact('ts:endpoint:' . $name, 'endpoint', $name, $name, Origin::Ast, Confidence::Certain, $evidence, [
                'method' => $method, 'uri' => $url, 'template' => $url, 'prefix' => 'known',
            ]);
            $edges[] = new EdgeFact('calls_endpoint', 'ts:module:' . $path, 'ts:endpoint:' . $name, Origin::Ast, Confidence::Certain, $evidence);
        }

        return new ScanContribution('knossos.typescript:file:' . $path, $nodes, $edges, []);
    }

    /**
     * A PHP file declaring Laravel routes, each [uri, line].
     *
     * @param list<array{0: string, 1: int}> $routes
     */
    protected static function laravelRoutes(string $path, array $routes): ScanContribution
    {
        $nodes = [];
        foreach ($routes as [$uri, $line]) {
            $name = 'GET ' . $uri;
            $nodes[] = new NodeFact('php:route:' . $name, 'route', $name, $name, Origin::FrameworkConvention, Confidence::Certain, new Evidence($path, $line, $line), [
                'framework' => 'laravel', 'methods' => ['GET'], 'path' => $uri,
            ]);
        }

        return new ScanContribution('knossos.php:file:' . $path, $nodes, [], []);
    }

    /**
     * @param list<ScanContribution> $contributions
     * @param list<string> $paths
     */
    protected static function reconcileEndpointGraph(SqliteGraphRepository $repository, array $contributions, array $paths): void
    {
        $scanners = [
            new ScannerManifest('knossos.typescript', '0.1.0', '1.0', '1.0', ['typescript'], ['ts'], []),
            new ScannerManifest('knossos.php', '0.1.0', '1.0', '1.0', ['php'], ['php'], []),
        ];
        (new GraphReconciler($repository))->reconcile(
            new FullScanRequest('endpoint-route', 'Endpoint Route', self::endpointDiscovery($paths), $scanners, $contributions),
        );
    }

    /** @param list<string> $paths */
    protected static function endpointDiscovery(array $paths): DiscoveryResult
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
