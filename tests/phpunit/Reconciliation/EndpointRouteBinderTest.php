<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Reconciliation;

use Knossos\Reconciliation\EndpointRouteBinder;
use Knossos\Reconciliation\EndpointRouteBinding;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

final class EndpointRouteBinderTest extends KnossosTestCase
{
    #[Group('reconciliation')]
    public function testAnExactLiteralBeatsAParameterRoute(): void
    {
        $binding = self::bind([
            self::endpoint('e', ['method' => 'GET', 'template' => '/orders/new']),
            self::route('new', ['methods' => ['GET'], 'path' => '/orders/new']),
            self::route('show', ['methods' => ['GET'], 'path' => '/orders/{id}']),
        ]);

        self::assertEdge($binding, 'new', 'certain', 'exact');
        assertSame([], $binding->warnings);
    }

    #[Group('reconciliation')]
    public function testAParameterRouteMatchesAConcreteId(): void
    {
        $binding = self::bind([
            self::endpoint('e', ['method' => 'GET', 'template' => '/orders/42']),
            self::route('show', ['methods' => ['GET'], 'path' => '/orders/{id}']),
        ]);

        self::assertEdge($binding, 'show', 'probable', 'parameter');
    }

    #[Group('reconciliation')]
    public function testAnUnknownRoutePrefixOnlyAllowsASuffixMatch(): void
    {
        $binding = self::bind([
            self::endpoint('e', ['method' => 'GET', 'template' => '/api/orders/42']),
            self::route('show', ['framework' => 'django', 'module' => 'shop.urls', 'path' => 'orders/{id}']),
        ]);

        self::assertEdge($binding, 'show', 'possible', 'suffix');
    }

    #[Group('reconciliation')]
    public function testACatchAllRouteIsNeverASuffixMatch(): void
    {
        $binding = self::bind([
            self::endpoint('e', ['method' => 'GET', 'template' => '/anything']),
            self::route('slug', ['methods' => ['GET'], 'path' => '/{slug}']),
        ]);

        // The all-parameter route still fits by parameter, so it is the only way an
        // endpoint reaches it; a longer URL must not.
        self::assertEdge($binding, 'slug', 'probable', 'parameter');

        $longer = self::bind([
            self::endpoint('e', ['method' => 'GET', 'template' => '/anything/deeper']),
            self::route('slug', ['methods' => ['GET'], 'path' => '/{slug}']),
        ]);
        assertSame([], $longer->edges);
        assertSame('ENDPOINT_ROUTE_UNMATCHED', $longer->warnings[0]['code']);
        assertSame('info', $longer->warnings[0]['severity']);
        assertSame('knossos.reconciliation:endpoint-routes', $longer->warnings[0]['owner']);
    }

    #[Group('reconciliation')]
    public function testTwoRoutesTheSameShapeAreReportedNotGuessed(): void
    {
        $binding = self::bind([
            self::endpoint('e', ['method' => 'GET', 'template' => '/orders/{}']),
            self::route('a', ['methods' => ['GET'], 'path' => '/orders/{id}']),
            self::route('b', ['methods' => ['GET'], 'path' => '/orders/{order}']),
        ]);

        assertSame([], $binding->edges);
        assertSame('ENDPOINT_ROUTE_AMBIGUOUS', $binding->warnings[0]['code']);
        assertSame('warning', $binding->warnings[0]['severity']);
        assertSame('GET /orders/{} matches 2 routes equally: route:a, route:b', $binding->warnings[0]['message']);
        assertSame('knossos.reconciliation:endpoint-routes', $binding->warnings[0]['owner']);
        assertSame(7, $binding->warnings[0]['start_line']);
    }

    #[Group('reconciliation')]
    public function testHeadFitsGetAndPostDoesNot(): void
    {
        $head = self::bind([
            self::endpoint('e', ['method' => 'HEAD', 'template' => '/health']),
            self::route('health', ['methods' => ['GET'], 'path' => '/health']),
        ]);
        self::assertEdge($head, 'health', 'certain', 'exact');

        $post = self::bind([
            self::endpoint('e', ['method' => 'POST', 'template' => '/health']),
            self::route('health', ['methods' => ['GET'], 'path' => '/health']),
        ]);
        assertSame([], $post->edges);
        assertSame('ENDPOINT_ROUTE_UNMATCHED', $post->warnings[0]['code']);
        assertSame('POST /health matches no route of this project', $post->warnings[0]['message']);
    }

    #[Group('reconciliation')]
    public function testAnyMethodEndpointMeetsADjangoRouteInTheRootUrlconf(): void
    {
        $binding = self::bind([
            [
                'id' => 'setting', 'kind' => 'setting', 'canonical_name' => 'proj.settings.ROOT_URLCONF',
                'attributes' => ['value' => 'proj.urls'], 'evidence_path' => 'proj/settings.py',
                'file_id' => 'f', 'start_line' => 1, 'end_line' => 1, 'owner_key' => 'o',
            ],
            self::endpoint('e', ['method' => 'ANY', 'template' => '/task/search/']),
            self::route('search', ['framework' => 'django', 'module' => 'proj.urls', 'path' => 'task/search/']),
        ]);

        self::assertEdge($binding, 'search', 'certain', 'exact');
    }

    #[Group('reconciliation')]
    public function testALaravelApiRouteIsFoundUnderItsConventionalPrefix(): void
    {
        $binding = self::bind([
            self::endpoint('e', ['method' => 'GET', 'template' => '/api/orders']),
            self::route('orders', ['framework' => 'laravel', 'methods' => ['GET'], 'path' => 'orders'], 'routes/api.php'),
        ]);

        self::assertEdge($binding, 'orders', 'probable', 'exact');
    }

    #[Group('reconciliation')]
    public function testARouteNameJoinsWithinOneFramework(): void
    {
        $nodes = [
            self::endpoint('e', ['route_name' => 'orders.show', 'framework' => 'laravel']),
            self::route('show', ['framework' => 'laravel', 'name' => 'orders.show', 'methods' => ['GET'], 'path' => 'orders/{id}']),
        ];
        self::assertEdge(self::bind($nodes), 'show', 'certain', 'name');

        $other = self::bind([
            self::endpoint('e', ['route_name' => 'orders.show', 'framework' => 'laravel']),
            self::route('sym', ['framework' => 'symfony', 'name' => 'orders.show', 'methods' => ['GET'], 'path' => '/orders/{id}']),
        ]);
        assertSame([], $other->edges);
        assertSame('ENDPOINT_ROUTE_UNMATCHED', $other->warnings[0]['code']);
    }

    #[Group('reconciliation')]
    public function testTwoRoutesWithOneNameAreAmbiguous(): void
    {
        $binding = self::bind([
            self::endpoint('e', ['route_name' => 'orders.show', 'framework' => 'laravel']),
            self::route('a', ['framework' => 'laravel', 'name' => 'orders.show', 'path' => 'a']),
            self::route('b', ['framework' => 'laravel', 'name' => 'orders.show', 'path' => 'b']),
        ]);

        assertSame([], $binding->edges);
        assertSame('ENDPOINT_ROUTE_AMBIGUOUS', $binding->warnings[0]['code']);
    }

    #[Group('reconciliation')]
    public function testAnExternalEndpointIsLeftAlone(): void
    {
        $binding = self::bind([
            self::endpoint('e', ['method' => 'GET', 'template' => '/nothing', 'external' => true]),
            self::route('show', ['methods' => ['GET'], 'path' => '/orders']),
        ]);

        assertSame([], $binding->edges);
        assertSame([], $binding->warnings);
    }

    #[Group('reconciliation')]
    public function testAGraphWithoutRoutesGetsNoWarnings(): void
    {
        $binding = self::bind([self::endpoint('e', ['method' => 'GET', 'template' => '/orders'])]);

        assertSame([], $binding->edges);
        assertSame([], $binding->warnings);
    }

    #[Group('reconciliation')]
    public function testACallerWithAnUnknownPrefixOnlyMatchesBySuffix(): void
    {
        $binding = self::bind([
            self::endpoint('e', ['method' => 'GET', 'template' => '/orders/42', 'prefix' => 'unknown']),
            self::route('show', ['methods' => ['GET'], 'path' => '/orders/{id}']),
        ]);

        self::assertEdge($binding, 'show', 'possible', 'suffix');
    }

    #[Group('reconciliation')]
    public function testTheEdgeCarriesTheEndpointsEvidence(): void
    {
        $binding = self::bind([
            self::endpoint('e', ['method' => 'GET', 'template' => '/orders']),
            self::route('list', ['methods' => ['GET'], 'path' => '/orders']),
        ]);

        $edge = array_values($binding->edges)[0];
        assertSame('resolves_to', $edge['kind']);
        assertSame('e', $edge['source_id']);
        assertSame('derived', $edge['origin']);
        assertSame('f-src/app.ts', $edge['file_id']);
        assertSame(7, $edge['start_line']);
        assertSame('o:src/app.ts', $edge['owner_key']);
        assertSame($edge['id'], array_key_first($binding->edges));
    }

    #[Group('reconciliation')]
    public function testTheRouteWithMoreLiteralSegmentsWinsAtTheSameGrade(): void
    {
        $binding = self::bind([
            self::endpoint('e', ['method' => 'GET', 'template' => '/orders/42']),
            self::route('wild', ['methods' => ['GET'], 'path' => '/{a}/{b}']),
            self::route('show', ['methods' => ['GET'], 'path' => '/orders/{id}']),
        ]);

        self::assertEdge($binding, 'show', 'probable', 'parameter');
        assertSame([], $binding->warnings);
    }

    #[Group('reconciliation')]
    public function testARouteReachedTwiceKeepsItsMoreLiteralTemplate(): void
    {
        $binding = self::bind([
            self::endpoint('e', ['method' => 'GET', 'template' => '/v1/items/42']),
            self::route('item', ['framework' => 'fastapi', 'methods' => ['GET'], 'path' => '/items/{id}', 'router' => 'py:router:items']),
            [
                'id' => 'r-items', 'kind' => 'router', 'canonical_name' => 'items', 'attributes' => ['scanner_local_id' => 'py:router:items'],
                'evidence_path' => 'src/routes.py', 'file_id' => 'f', 'start_line' => 1, 'end_line' => 1, 'owner_key' => 'o',
            ],
        ], [
            'm1' => ['id' => 'm1', 'kind' => 'mounts', 'source_id' => 'x', 'target_id' => 'r-items', 'attributes' => ['prefix' => '/{tenant}', 'onto' => 'app']],
            'm2' => ['id' => 'm2', 'kind' => 'mounts', 'source_id' => 'x', 'target_id' => 'r-items', 'attributes' => ['prefix' => '/v1', 'onto' => 'app']],
        ]);

        self::assertEdge($binding, 'item', 'probable', 'parameter');
        assertSame(['m2'], array_values($binding->edges)[0]['attributes']['prefix_from']);
    }

    #[Group('reconciliation')]
    public function testARouteWithoutAReadablePathStillCountsAsARouteOfTheProject(): void
    {
        $binding = self::bind([
            self::endpoint('e', ['method' => 'GET', 'template' => '/orders']),
            self::route('dynamic', ['methods' => ['GET']]),
        ]);

        assertSame([], $binding->edges);
        assertSame('ENDPOINT_ROUTE_UNMATCHED', $binding->warnings[0]['code']);
    }

    #[Group('reconciliation')]
    public function testANameEndpointWithoutAFrameworkMatchesNothing(): void
    {
        $binding = self::bind([
            self::endpoint('e', ['route_name' => 'orders.show']),
            self::route('show', ['name' => 'orders.show', 'methods' => ['GET'], 'path' => '/orders/{id}']),
        ]);

        assertSame([], $binding->edges);
        assertSame('ENDPOINT_ROUTE_UNMATCHED', $binding->warnings[0]['code']);
    }

    #[Group('reconciliation')]
    public function testAnyMethodEndpointReachesARouteOfAnyMethod(): void
    {
        $binding = self::bind([
            self::endpoint('e', ['method' => 'ANY', 'template' => '/jobs']),
            self::route('create', ['methods' => ['POST'], 'path' => '/jobs']),
        ]);

        self::assertEdge($binding, 'create', 'certain', 'exact');
    }

    #[Group('reconciliation')]
    public function testManyRoutesAndEndpointsAreJoinedCorrectly(): void
    {
        $records = [];
        for ($i = 0; $i < 2000; ++$i) {
            $records[] = self::route('route-' . $i, ['methods' => ['GET', 'POST'], 'path' => sprintf('/area%d/items/{id}', $i)]);
            $records[] = self::endpoint('endpoint-' . $i, ['method' => 'POST', 'template' => sprintf('/area%d/items/%d', $i, $i)]);
        }
        $records[] = self::endpoint('lost', ['method' => 'GET', 'template' => '/area0/other/1']);
        $records[] = self::endpoint('proxied', ['method' => 'GET', 'template' => '/proxy/area7/items/3']);

        $binding = self::bind($records);

        $targets = [];
        foreach ($binding->edges as $edge) {
            $targets[$edge['source_id']] = [$edge['target_id'], $edge['confidence']];
        }
        assertSame(2001, count($binding->edges));
        assertSame(['route-0', 'probable'], $targets['endpoint-0']);
        assertSame(['route-1234', 'probable'], $targets['endpoint-1234']);
        assertSame(['route-7', 'possible'], $targets['proxied']);
        assertSame(['ENDPOINT_ROUTE_UNMATCHED'], array_column($binding->warnings, 'code'));
    }

    /**
     * @param list<array<string, mixed>> $records nodes
     * @param array<string, array<string, mixed>> $edges
     */
    private static function bind(array $records, array $edges = []): EndpointRouteBinding
    {
        $nodes = [];
        foreach ($records as $record) {
            $nodes[$record['id']] = $record;
        }

        return (new EndpointRouteBinder('project'))->bind($nodes, $edges);
    }

    private static function assertEdge(EndpointRouteBinding $binding, string $routeId, string $confidence, string $match): void
    {
        assertSame(1, count($binding->edges));
        $edge = array_values($binding->edges)[0];
        assertSame($routeId, $edge['target_id']);
        assertSame($confidence, $edge['confidence']);
        assertSame($match, $edge['attributes']['match']);
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private static function route(string $id, array $attributes, string $path = 'src/routes.php'): array
    {
        return [
            'id' => $id, 'kind' => 'route', 'canonical_name' => 'route:' . $id, 'attributes' => $attributes, 'evidence_path' => $path,
            'file_id' => 'f-' . $path, 'start_line' => 1, 'end_line' => 1, 'owner_key' => 'o:' . $path,
        ];
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private static function endpoint(string $id, array $attributes): array
    {
        return [
            'id' => $id, 'kind' => 'endpoint', 'canonical_name' => strtoupper((string) ($attributes['method'] ?? 'ANY')) . ' ' . ($attributes['template'] ?? ''),
            'attributes' => $attributes, 'evidence_path' => 'src/app.ts',
            'file_id' => 'f-src/app.ts', 'start_line' => 7, 'end_line' => 7, 'owner_key' => 'o:src/app.ts',
        ];
    }
}
