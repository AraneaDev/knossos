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
            self::route('new', ['methods' => ['GET'], 'uri' => '/orders/new']),
            self::route('show', ['methods' => ['GET'], 'uri' => '/orders/{id}']),
        ]);

        self::assertEdge($binding, 'new', 'certain', 'exact');
        assertSame([], $binding->warnings);
    }

    #[Group('reconciliation')]
    public function testAParameterRouteMatchesAConcreteId(): void
    {
        $binding = self::bind([
            self::endpoint('e', ['method' => 'GET', 'template' => '/orders/42']),
            self::route('show', ['methods' => ['GET'], 'uri' => '/orders/{id}']),
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
            self::route('slug', ['methods' => ['GET'], 'uri' => '/{slug}']),
        ]);

        // The all-parameter route still fits by parameter, so it is the only way an
        // endpoint reaches it; a longer URL must not.
        self::assertEdge($binding, 'slug', 'probable', 'parameter');

        $longer = self::bind([
            self::endpoint('e', ['method' => 'GET', 'template' => '/anything/deeper']),
            self::route('slug', ['methods' => ['GET'], 'uri' => '/{slug}']),
        ]);
        assertSame([], $longer->edges);
        assertSame('ENDPOINT_ROUTE_UNMATCHED', $longer->warnings[0]['code']);
        assertSame('info', $longer->warnings[0]['severity']);
    }

    #[Group('reconciliation')]
    public function testTwoRoutesTheSameShapeAreReportedNotGuessed(): void
    {
        $binding = self::bind([
            self::endpoint('e', ['method' => 'GET', 'template' => '/orders/{}']),
            self::route('a', ['methods' => ['GET'], 'uri' => '/orders/{id}']),
            self::route('b', ['methods' => ['GET'], 'uri' => '/orders/{order}']),
        ]);

        assertSame([], $binding->edges);
        assertSame('ENDPOINT_ROUTE_AMBIGUOUS', $binding->warnings[0]['code']);
        assertSame('warning', $binding->warnings[0]['severity']);
        assertSame('GET /orders/{} matches 2 routes equally: route:a, route:b', $binding->warnings[0]['message']);
        assertSame(7, $binding->warnings[0]['start_line']);
    }

    #[Group('reconciliation')]
    public function testHeadFitsGetAndPostDoesNot(): void
    {
        $head = self::bind([
            self::endpoint('e', ['method' => 'HEAD', 'template' => '/health']),
            self::route('health', ['methods' => ['GET'], 'uri' => '/health']),
        ]);
        self::assertEdge($head, 'health', 'certain', 'exact');

        $post = self::bind([
            self::endpoint('e', ['method' => 'POST', 'template' => '/health']),
            self::route('health', ['methods' => ['GET'], 'uri' => '/health']),
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
            self::route('orders', ['framework' => 'laravel', 'methods' => ['GET'], 'uri' => 'orders'], 'routes/api.php'),
        ]);

        self::assertEdge($binding, 'orders', 'probable', 'exact');
    }

    #[Group('reconciliation')]
    public function testARouteNameJoinsWithinOneFramework(): void
    {
        $nodes = [
            self::endpoint('e', ['route_name' => 'orders.show', 'framework' => 'laravel']),
            self::route('show', ['framework' => 'laravel', 'name' => 'orders.show', 'methods' => ['GET'], 'uri' => 'orders/{id}']),
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
            self::route('a', ['framework' => 'laravel', 'name' => 'orders.show', 'uri' => 'a']),
            self::route('b', ['framework' => 'laravel', 'name' => 'orders.show', 'uri' => 'b']),
        ]);

        assertSame([], $binding->edges);
        assertSame('ENDPOINT_ROUTE_AMBIGUOUS', $binding->warnings[0]['code']);
    }

    #[Group('reconciliation')]
    public function testAnExternalEndpointIsLeftAlone(): void
    {
        $binding = self::bind([
            self::endpoint('e', ['method' => 'GET', 'template' => '/nothing', 'external' => true]),
            self::route('show', ['methods' => ['GET'], 'uri' => '/orders']),
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
            self::route('show', ['methods' => ['GET'], 'uri' => '/orders/{id}']),
        ]);

        self::assertEdge($binding, 'show', 'possible', 'suffix');
    }

    #[Group('reconciliation')]
    public function testTheEdgeCarriesTheEndpointsEvidence(): void
    {
        $binding = self::bind([
            self::endpoint('e', ['method' => 'GET', 'template' => '/orders']),
            self::route('list', ['methods' => ['GET'], 'uri' => '/orders']),
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
