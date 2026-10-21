<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Scanner\Worker\ProcessScannerClient;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\Support\SchemaSubset;

use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Every worker's route nodes carry the attributes the contribution schema
 * names for a route: `framework`, upper-case `methods` and `path`, so the core
 * reads one shape instead of papering over four.
 */
#[Group('scanner')]
final class RouteAttributesAgreementTest extends KnossosTestCase
{
    /**
     * Each worker, a tree with routes in it, and the extra request fields it needs.
     *
     * @return iterable<string, array{0: string, 1: array<string, string>, 2: array<string, mixed>}>
     */
    public static function workers(): iterable
    {
        yield 'php laravel' => ['php', ['routes/web.php' => <<<'PHP'
            <?php
            use Illuminate\Support\Facades\Route;

            Route::match(['get', 'post'], '/orders', [OrderController::class, 'store']);
            Route::any('/anything', [OrderController::class, 'any']);
            PHP], ['frameworks' => ['laravel']]];
        yield 'php symfony' => ['php', ['src/OrderController.php' => <<<'PHP'
            <?php
            namespace App;

            use Symfony\Component\Routing\Attribute\Route;

            final class OrderController
            {
                #[Route('/orders', methods: ['get', 'POST'])]
                public function store() {}

                #[Route('/anything')]
                public function any() {}
            }
            PHP], ['frameworks' => ['symfony']]];
        yield 'python' => ['python', [
            'app/__init__.py' => '',
            'app/api.py' => "from fastapi import FastAPI\n\napp = FastAPI()\n\n\n@app.get(\"/orders\")\ndef orders():\n    return []\n",
            'app/urls.py' => "from django.urls import path\n\n\ndef view(request):\n    return None\n\n\nurlpatterns = [path(\"orders/\", view)]\n",
        ], []];
        yield 'typescript' => ['typescript', [
            'tsconfig.json' => '{"compilerOptions":{"experimentalDecorators":true,"noEmit":true},"include":["src/**/*.ts"]}',
            'src/orders.controller.ts' => <<<'TS'
                import { All, Controller, Get } from '@nestjs/common';

                @Controller('orders')
                export class OrdersController {
                    @Get()
                    list() { return []; }

                    @All('any')
                    any() { return 1; }
                }
                TS,
        ], ['config_files' => ['tsconfig.json'], 'frameworks' => ['nestjs']]];
        yield 'rust' => ['rust', ['src/lib.rs' => <<<'RS'
            use axum::Router;
            use axum::routing::{any, get};

            fn orders() {}
            fn anything() {}

            fn app() -> Router {
                Router::new().route("/orders", get(orders)).route("/anything", any(anything))
            }
            RS], ['frameworks' => ['axum']]];
    }

    /**
     * @param array<string, string> $files
     * @param array<string, mixed> $request
     */
    #[DataProvider('workers')]
    public function testRouteNodesCarryTheSchemaRouteAttributes(string $worker, array $files, array $request): void
    {
        if ($worker === 'rust' && !is_executable(self::rustWorkerBinary())) {
            self::markTestSkipped('The Rust worker is not built.');
        }
        $schema = json_decode((string) file_get_contents(self::repositoryRoot() . '/schemas/scanner/v1/contribution.schema.json'), true, 512, JSON_THROW_ON_ERROR);
        $routes = [];
        foreach ($this->scan($worker, $files, $request) as $contribution) {
            foreach ($contribution->nodes as $node) {
                if ($node->kind === 'route') {
                    $routes[$node->canonicalName] = $node->attributes;
                }
            }
        }

        assertNotSame([], $routes);
        foreach ($routes as $canonical => $attributes) {
            assertSame([], SchemaSubset::errors(['$ref' => '#/$defs/routeAttributes'], $attributes, $schema), $canonical);
            assertSame(false, array_key_exists('uri', $attributes) || array_key_exists('http_methods', $attributes), $canonical);
        }
    }

    /**
     * Scan the files in a temporary tree with one worker.
     *
     * @param array<string, string> $files
     * @param array<string, mixed> $request
     * @return list<ScanContribution>
     */
    private function scan(string $worker, array $files, array $request): array
    {
        $root = sys_get_temp_dir() . '/knossos-stale-route-attributes-' . bin2hex(random_bytes(5));
        foreach ($files as $path => $contents) {
            @mkdir(dirname($root . '/' . $path), 0o777, true);
            file_put_contents($root . '/' . $path, $contents);
        }
        $root = (string) realpath($root);
        $client = $this->client($worker);
        try {
            $sources = array_values(array_filter(array_keys($files), static fn(string $path): bool => $path !== 'tsconfig.json'));

            return iterator_to_array($client->scan([
                'root' => $root,
                'files' => $sources,
                'limits' => ['max_files' => 100, 'max_file_bytes' => 100_000],
                'source_files' => $sources,
                ...$request,
            ]), false);
        } finally {
            $client->shutdown();
            $this->removeTempTree($root);
        }
    }

    /** The test client for one worker. */
    private function client(string $worker): ProcessScannerClient
    {
        return match ($worker) {
            'php' => $this->phpWorkerClient(),
            'python' => $this->pythonWorkerClient(),
            'typescript' => $this->typescriptWorkerClient(),
            default => $this->rustWorkerClient(),
        };
    }
}
