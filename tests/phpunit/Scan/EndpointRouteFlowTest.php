<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertSame;

/**
 * A request travels from the code that makes it, through the route that
 * serves it, to the handler, across languages and across scans.
 */
final class EndpointRouteFlowTest extends KnossosTestCase
{
    // A method of an object-literal component is named after its position in the file.
    private const LOAD = 'frontend/src/components/OrderList.vue#{object}@4:14::load';
    private const SEARCH = 'frontend/src/components/OrderList.vue#{object}@4:14::search';

    #[Group('scan')]
    public function testAVueComponentReachesTheFastApiHandlerThroughTheRoute(): void
    {
        $hops = $this->hops('endpoint-routes', self::LOAD, 'backend.app.api.show');

        $kinds = array_column($hops, 'kind');
        assertSame(['calls_endpoint', 'resolves_to', 'routes_to'], array_slice($kinds, -3));
        // The component's URL holds a parameter, so the join is a parameter match.
        $hop = $hops[array_search('resolves_to', $kinds, true)];
        assertSame('probable', $hop['confidence']);
        assertSame('parameter', $hop['attributes']['match']);
        assertSame('derived', $hop['origin']);
    }

    #[Group('scan')]
    public function testASearchCallReachesTheDjangoViewThroughACertainJoin(): void
    {
        $hops = $this->hops('endpoint-routes', self::SEARCH, 'backend.app.urls.search');

        $kinds = array_column($hops, 'kind');
        assertSame(['calls_endpoint', 'resolves_to', 'routes_to'], array_slice($kinds, -3));
        $hop = $hops[array_search('resolves_to', $kinds, true)];
        assertSame('certain', $hop['confidence']);
        assertSame('exact', $hop['attributes']['match']);
    }

    #[Group('scan')]
    public function testALaravelControllerReachesTheRouteItNamesAndItsHandler(): void
    {
        $hops = $this->hops(
            'laravel-route-names',
            'App\\Http\\Controllers\\OrderController::store',
            'App\\Http\\Controllers\\OrderController::show',
        );

        $kinds = array_column($hops, 'kind');
        assertSame(['calls_endpoint', 'resolves_to', 'routes_to'], array_slice($kinds, -3));
        $hop = $hops[array_search('resolves_to', $kinds, true)];
        assertSame('name', $hop['attributes']['match']);
        assertSame('certain', $hop['confidence']);
        assertSame('derived', $hop['origin']);
    }

    #[Group('scan')]
    public function testMovingARouteDropsTheJoinOfAnEndpointWhoseFileCameFromTheCache(): void
    {
        [$pdo, , $root] = $this->scanTempFixture('endpoint-routes');
        try {
            $joined = $this->resolvesFromLoad($pdo);
            assertSame(1, count($joined));

            $api = $root . '/backend/app/api.py';
            $source = (string) file_get_contents($api);
            $moved = str_replace('prefix="/api/v1"', 'prefix="/api/v2"', $source);
            assertNotSame($source, $moved);
            file_put_contents($api, $moved);
            touch($api, time() + 5);

            $result = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);

            assertSame('incremental', $result->data['mode']);
            assertSame(1, $result->data['parsed_files']);
            assertSame([], $this->resolvesFromLoad($pdo));
            $messages = $pdo->query("SELECT message FROM diagnostics d JOIN projects p ON p.id = d.project_id AND p.active_scan_id = d.scan_id WHERE d.code = 'ENDPOINT_ROUTE_UNMATCHED'")->fetchAll(\PDO::FETCH_COLUMN);
            $named = array_filter($messages, fn(string $message): bool => str_contains($message, 'GET /api/v1/items/{}'));
            assertSame(1, count($named), implode("\n", $messages));

            // The caller follows the route: the edge moves to the new template.
            $component = $root . '/frontend/src/components/OrderList.vue';
            $edited = str_replace('/api/v1/items/', '/api/v2/items/', (string) file_get_contents($component));
            file_put_contents($component, $edited);
            touch($component, time() + 10);

            $second = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);

            assertSame('incremental', $second->data['mode']);
            $moved = $this->resolvesFromLoad($pdo, 'GET /api/v2/items/{}');
            assertSame(1, count($moved));
            assertSame([], $this->resolvesFromLoad($pdo));
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** @return list<array<string, mixed>> */
    private function hops(string $fixture, string $from, string $to): array
    {
        $pdo = $this->freshTestDatabase();
        $root = self::repositoryRoot() . '/tests/Fixtures/' . $fixture;
        $result = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);
        $flow = ArchitectureQueryService::forDatabase($pdo)->explainFlow($result->projectId, $from, $to);

        return $flow->data['paths'][0]['hops'];
    }

    /** @return list<string> */
    private function resolvesFromLoad(\PDO $pdo, string $endpoint = 'GET /api/v1/items/{}'): array
    {
        $statement = $pdo->prepare(<<<'SQL'
            SELECT t.canonical_name FROM edges e
            JOIN nodes s ON s.id = e.source_id
            JOIN nodes t ON t.id = e.target_id
            WHERE e.kind = 'resolves_to' AND s.kind = 'endpoint' AND s.canonical_name = :endpoint
            SQL);
        $statement->execute(['endpoint' => $endpoint]);

        return $statement->fetchAll(\PDO::FETCH_COLUMN);
    }
}
