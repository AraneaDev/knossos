<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Framework;

use Knossos\Scanner\Protocol\EdgeFact;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

use function PHPUnit\Framework\assertArrayHasKey;
use function PHPUnit\Framework\assertSame;

/**
 * The URLs a Python caller requests become `endpoint` nodes carrying the path
 * template the core joins to a route, and the routes say which router they
 * hang under.
 */
#[Group('python-frameworks')]
final class PythonEndpointsTest extends KnossosTestCase
{
    public function testRequestsAndTasksBecomeEndpointsWithTheirPathTemplate(): void
    {
        [$nodes, $edges] = $this->scan();
        $endpoints = $this->endpoints($nodes);

        assertSame(
            ['framework' => 'requests', 'method' => 'GET', 'uri' => '/api/orders', 'template' => '/api/orders', 'prefix' => 'known', 'external' => false],
            $endpoints['GET /api/orders'],
        );
        assertSame('/api/orders/{}/lines', $endpoints['POST /api/orders/{}/lines']['template']);
        assertSame('/api/orders/{}', $endpoints['DELETE /api/orders/{}']['template']);
        assertSame('httpx', $endpoints['PATCH /api/orders/1']['framework']);
        assertSame(true, $endpoints['GET https://example.com/x']['external']);
        assertSame('appengine', $endpoints['POST /task/search/']['framework']);
        assertSame('/task/search', $endpoints['POST /task/search/']['template']);
        assertArrayHasKey('GET /task/reindex/', $endpoints);

        $called = $this->targets($edges, 'calls_endpoint', 'py:function:app.clients.sync');
        foreach (['GET /api/orders', 'POST /api/orders/{}/lines', 'DELETE /api/orders/{}', 'PATCH /api/orders/1', 'GET https://example.com/x'] as $name) {
            assertSame(true, in_array('py:endpoint:' . $name, $called, true), $name);
        }
        $dispatched = $this->targets($edges, 'dispatches', 'py:function:app.clients.enqueue');
        sort($dispatched);
        assertSame(['py:endpoint:GET /task/reindex/', 'py:endpoint:POST /task/search/'], $dispatched);
    }

    public function testASubstitutionShapesTheTemplateTheWayTheTypescriptWorkerDoes(): void
    {
        [$nodes] = $this->scan();
        $endpoints = $this->endpoints($nodes);

        $unknown = $endpoints['GET {}/orders'];
        assertSame(['{}/orders', '/orders', 'unknown'], [$unknown['uri'], $unknown['template'], $unknown['prefix']]);
        assertSame('/api/{}', $endpoints['GET /api/orders{}']['template']);
        assertSame('/api/{}', $endpoints['GET /api/{}-list']['template']);
        assertSame('known', $endpoints['GET /api/{}-list']['prefix']);
        assertArrayHasKey('GET /api/orders', $endpoints);
        foreach (array_keys($endpoints) as $name) {
            assertSame(false, $name === 'GET {}{}' || $name === 'GET {}', $name);
        }
    }

    public function testRoutesNameTheRouterTheyHangUnderAndMountsNameWhatTheyHangOn(): void
    {
        [$nodes, $edges] = $this->scan();
        $routes = [];
        foreach ($nodes as $node) {
            if ($node->kind === 'route') {
                $routes[$node->displayName] = $node->attributes;
            }
        }

        assertSame('py:router:app.api.router', $routes['GET /items/{item_id}']['router']);
        assertSame(false, array_key_exists('router', $routes['GET /health']));
        assertSame('app.urls', $routes['ANY /task/search/']['module']);
        $mounts = array_values(array_filter($edges, fn(EdgeFact $edge): bool => $edge->kind === 'mounts'));
        assertSame(1, count($mounts));
        assertSame('py:router:app.api.router', $mounts[0]->targetReference);
        assertSame(['app', '/v1'], [$mounts[0]->attributes['onto'], $mounts[0]->attributes['prefix']]);
    }

    /** @return array{0: list<NodeFact>, 1: list<EdgeFact>} */
    private function scan(): array
    {
        $client = $this->pythonWorkerClient();
        $contributions = iterator_to_array($client->scan([
            'root' => self::repositoryRoot() . '/tests/Fixtures/python-endpoints',
            'files' => ['app/__init__.py', 'app/api.py', 'app/clients.py', 'app/urls.py'],
        ]));
        $client->shutdown();

        return [
            array_merge(...array_map(fn(ScanContribution $item): array => $item->nodes, $contributions)),
            array_merge(...array_map(fn(ScanContribution $item): array => $item->edges, $contributions)),
        ];
    }

    /**
     * @param list<NodeFact> $nodes
     * @return array<string, array<string, mixed>>
     */
    private function endpoints(array $nodes): array
    {
        $endpoints = [];
        foreach ($nodes as $node) {
            if ($node->kind === 'endpoint') {
                $endpoints[$node->displayName] = $node->attributes;
            }
        }

        return $endpoints;
    }

    /**
     * @param list<EdgeFact> $edges
     * @return list<string>
     */
    private function targets(array $edges, string $kind, string $source): array
    {
        return array_values(array_map(
            fn(EdgeFact $edge): string => $edge->targetReference,
            array_filter($edges, fn(EdgeFact $edge): bool => $edge->kind === $kind && $edge->sourceReference === $source),
        ));
    }
}
