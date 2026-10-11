<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Framework;

use Knossos\Scanner\Protocol\EdgeFact;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

use function PHPUnit\Framework\assertSame;

/**
 * A reference to a route by its name becomes an `endpoint` node carrying that
 * name, so the core can join it to the route the name belongs to.
 */
#[Group('laravel')]
final class LaravelRouteNamesTest extends KnossosTestCase
{
    public function testEachLiteralRouteNameReferenceBecomesAnEndpointCalledFromItsMethod(): void
    {
        [$nodes, $edges] = $this->scan();

        $names = [];
        foreach ($nodes as $node) {
            if ($node->kind === 'endpoint') {
                $names[] = $node->attributes['route_name'];
                assertSame('laravel', $node->attributes['framework']);
            }
        }
        sort($names);
        assertSame(['orders.create', 'orders.edit', 'orders.index', 'orders.list', 'orders.show'], $names);

        $called = [];
        foreach ($edges as $edge) {
            if ($edge->kind === 'calls_endpoint' && $edge->sourceReference === 'php:method:App\\Http\\Controllers\\OrderController::store') {
                $called[] = $edge->targetReference;
            }
        }
        sort($called);
        assertSame(
            array_map(fn(string $name): string => 'php:endpoint:route laravel:' . $name, $names),
            $called,
        );
    }

    public function testARouteNodeNamesTheFrameworkItBelongsTo(): void
    {
        [$nodes] = $this->scan();

        $routes = array_values(array_filter($nodes, fn(NodeFact $node): bool => $node->kind === 'route'));
        assertSame(1, count($routes));
        assertSame('laravel', $routes[0]->attributes['framework']);
        assertSame('orders.show', $routes[0]->attributes['name']);
    }

    /** @return array{0: list<NodeFact>, 1: list<EdgeFact>} */
    private function scan(): array
    {
        $root = sys_get_temp_dir() . '/knossos-stale-laravel-names-' . bin2hex(random_bytes(4));
        mkdir($root . '/app/Http/Controllers', 0o777, true);
        mkdir($root . '/routes', 0o777, true);
        file_put_contents($root . '/app/Http/Controllers/OrderController.php', <<<'PHP'
            <?php
            namespace App\Http\Controllers;

            use Illuminate\Support\Facades\URL;
            use Illuminate\Support\Facades\Redirect;

            final class OrderController
            {
                public function store()
                {
                    $a = route('orders.show', ['order' => 1]);
                    $b = to_route('orders.index');
                    $c = redirect()->route('orders.edit', 1);
                    $d = URL::route('orders.create');
                    $e = Redirect::route('orders.list');
                    $f = route($dynamic);
                    $g = request()->routeIs('orders.*');
                    return [$a, $b, $c, $d, $e, $f, $g];
                }
            }
            PHP);
        file_put_contents($root . '/routes/web.php', <<<'PHP'
            <?php
            use App\Http\Controllers\OrderController;
            use Illuminate\Support\Facades\Route;

            Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
            PHP);
        try {
            $client = $this->phpWorkerClient();
            $contributions = iterator_to_array($client->scan([
                'root' => $root,
                'files' => ['app/Http/Controllers/OrderController.php', 'routes/web.php'],
                'frameworks' => ['laravel'],
            ]));
            $client->shutdown();
        } finally {
            $this->removeTempTree($root);
        }

        return [
            array_merge(...array_map(fn(ScanContribution $item): array => $item->nodes, $contributions)),
            array_merge(...array_map(fn(ScanContribution $item): array => $item->edges, $contributions)),
        ];
    }
}
