<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Reconciliation;

use Knossos\Reconciliation\RouteTemplates;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

final class RouteTemplatesTest extends KnossosTestCase
{
    #[Group('reconciliation')]
    public function testAMountedRouterPrefixesItsRoutes(): void
    {
        $nodes = [
            'route-1' => self::route('route-1', ['framework' => 'fastapi', 'methods' => ['get'], 'path' => '/items/{id}', 'router' => 'py:router:app.items.router']),
            'r-items' => self::router('r-items', 'py:router:app.items.router'),
        ];
        $edges = ['m1' => self::mount('m1', 'r-items', '/v1', 'app')];

        $routes = RouteTemplates::forRoutes($nodes, $edges);

        assertSame(['GET'], $routes['route-1']['methods']);
        assertSame(1, count($routes['route-1']['templates']));
        $template = $routes['route-1']['templates'][0];
        assertSame('/v1/items/{}', (string) $template['template']);
        assertSame('declared', $template['prefix']);
        assertSame(['m1'], $template['mounts']);
    }

    #[Group('reconciliation')]
    public function testARouterMountedTwiceGivesTwoTemplates(): void
    {
        $nodes = [
            'route-1' => self::route('route-1', ['framework' => 'fastapi', 'path' => '/items', 'router' => 'py:router:items']),
            'r-items' => self::router('r-items', 'py:router:items'),
        ];
        $edges = [
            'm1' => self::mount('m1', 'r-items', '/v1', 'app'),
            'm2' => self::mount('m2', 'r-items', '/v2', 'app'),
        ];

        $templates = RouteTemplates::forRoutes($nodes, $edges)['route-1']['templates'];

        assertSame(['/v1/items', '/v2/items'], array_map(static fn(array $t): string => (string) $t['template'], $templates));
    }

    #[Group('reconciliation')]
    public function testARouterMountedOntoAnotherRouterChainsUpToTheApp(): void
    {
        $nodes = [
            'route-1' => self::route('route-1', ['framework' => 'fastapi', 'path' => '/items', 'router' => 'py:router:items']),
            'r-items' => self::router('r-items', 'py:router:items'),
            'r-api' => self::router('r-api', 'py:router:api'),
        ];
        $edges = [
            'm1' => self::mount('m1', 'r-items', '/items-v', 'py:router:api'),
            'm2' => self::mount('m2', 'r-api', '/api', 'app'),
        ];

        $template = RouteTemplates::forRoutes($nodes, $edges)['route-1']['templates'][0];

        assertSame('/api/items-v/items', (string) $template['template']);
        assertSame('declared', $template['prefix']);
        assertSame(['m2', 'm1'], $template['mounts']);
    }

    #[Group('reconciliation')]
    public function testARouterWithNoMountHasAnUnknownPrefix(): void
    {
        $nodes = [
            'route-1' => self::route('route-1', ['framework' => 'fastapi', 'path' => '/items/{id}', 'router' => 'py:router:items']),
            'r-items' => self::router('r-items', 'py:router:items'),
        ];

        $template = RouteTemplates::forRoutes($nodes, [])['route-1']['templates'][0];

        assertSame('/items/{}', (string) $template['template']);
        assertSame('unknown', $template['prefix']);
    }

    #[Group('reconciliation')]
    public function testAllMeansAnyMethod(): void
    {
        $nodes = ['route-1' => self::route('route-1', ['framework' => 'nestjs', 'http_methods' => ['ALL'], 'path' => '/cats'])];

        $routes = RouteTemplates::forRoutes($nodes, []);

        assertSame(['ANY'], $routes['route-1']['methods']);
        assertSame('declared', $routes['route-1']['templates'][0]['prefix']);
    }

    #[Group('reconciliation')]
    public function testADjangoRouteIsDeclaredOnlyInTheRootUrlconf(): void
    {
        $nodes = [
            'setting' => [
                'id' => 'setting', 'kind' => 'setting', 'canonical_name' => 'proj.settings.ROOT_URLCONF',
                'attributes' => ['value' => 'proj.urls'], 'evidence_path' => 'proj/settings.py',
            ],
            'in-root' => self::route('in-root', ['framework' => 'django', 'path' => 'a/', 'module' => 'proj.urls']),
            'elsewhere' => self::route('elsewhere', ['framework' => 'django', 'path' => 'b/', 'module' => 'shop.urls']),
        ];

        $routes = RouteTemplates::forRoutes($nodes, []);

        assertSame('declared', $routes['in-root']['templates'][0]['prefix']);
        assertSame('unknown', $routes['elsewhere']['templates'][0]['prefix']);
    }

    #[Group('reconciliation')]
    public function testMountsThatLoopEndTheChainAtTheFirstRepeat(): void
    {
        $nodes = [
            'route-1' => self::route('route-1', ['framework' => 'fastapi', 'path' => '/items', 'router' => 'py:router:a']),
            'r-a' => self::router('r-a', 'py:router:a'),
            'r-b' => self::router('r-b', 'py:router:b'),
        ];
        $edges = [
            'm1' => self::mount('m1', 'r-a', '/a', 'py:router:b'),
            'm2' => self::mount('m2', 'r-b', '/b', 'py:router:a'),
        ];

        $templates = RouteTemplates::forRoutes($nodes, $edges)['route-1']['templates'];

        assertSame(1, count($templates));
        assertSame('/b/a/items', (string) $templates[0]['template']);
        assertSame('unknown', $templates[0]['prefix']);
        assertSame(['m2', 'm1'], $templates[0]['mounts']);
    }

    #[Group('reconciliation')]
    public function testAChainDeeperThanTheLimitIsUnknown(): void
    {
        $nodes = ['route-1' => self::route('route-1', ['framework' => 'fastapi', 'path' => '/x', 'router' => 'py:router:r0'])];
        $edges = [];
        for ($i = 0; $i < 12; ++$i) {
            $nodes['r' . $i] = self::router('r' . $i, 'py:router:r' . $i);
            $edges['m' . $i] = self::mount('m' . $i, 'r' . $i, '/p' . $i, $i === 11 ? 'app' : 'py:router:r' . ($i + 1));
        }

        $template = RouteTemplates::forRoutes($nodes, $edges)['route-1']['templates'][0];

        assertSame('unknown', $template['prefix']);
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private static function route(string $id, array $attributes): array
    {
        return [
            'id' => $id, 'kind' => 'route', 'canonical_name' => 'route:' . $id, 'attributes' => $attributes,
            'evidence_path' => 'src/routes.py', 'file_id' => 'f', 'start_line' => 1, 'end_line' => 1, 'owner_key' => 'o',
        ];
    }

    /** @return array<string, mixed> */
    private static function router(string $id, string $local): array
    {
        return [
            'id' => $id, 'kind' => 'router', 'canonical_name' => $local, 'attributes' => ['scanner_local_id' => $local],
            'evidence_path' => 'src/routes.py', 'file_id' => 'f', 'start_line' => 1, 'end_line' => 1, 'owner_key' => 'o',
        ];
    }

    /** @return array<string, mixed> */
    private static function mount(string $id, string $target, string $prefix, string $onto): array
    {
        return [
            'id' => $id, 'kind' => 'mounts', 'source_id' => 'x', 'target_id' => $target,
            'attributes' => ['prefix' => $prefix, 'onto' => $onto],
        ];
    }
}
