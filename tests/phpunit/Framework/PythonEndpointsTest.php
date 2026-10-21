<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Framework;

use Knossos\Scanner\Protocol\EdgeFact;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\Support\ScanLimits;

use function PHPUnit\Framework\assertArrayHasKey;
use function PHPUnit\Framework\assertArrayNotHasKey;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

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
        assertArrayNotHasKey('GET {}{}', $endpoints);
        assertArrayNotHasKey('GET {}', $endpoints);
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

    public function testOptionsAndKeywordOnlyRequestsAreRead(): void
    {
        [$nodes] = $this->scanSource(['app/__init__.py' => '', 'app/c.py' => <<<'PY'
            import httpx
            import requests


            def call() -> None:
                requests.options("/api/a")
                httpx.options("/api/b")
                requests.request(method="PUT", url="/api/c")
                httpx.request(url="/api/d", method="DELETE")
            PY]);
        $endpoints = $this->endpoints($nodes);

        foreach (['OPTIONS /api/a', 'OPTIONS /api/b', 'PUT /api/c', 'DELETE /api/d'] as $name) {
            assertArrayHasKey($name, $endpoints);
        }
    }

    public function testAMethodThatIsNotLiteralMakesAnEndpointForAnyMethod(): void
    {
        [$nodes] = $this->scanSource(['app/__init__.py' => '', 'app/c.py' => <<<'PY'
            import httpx
            import requests
            from google.appengine.api import taskqueue


            def call(verb: str) -> None:
                requests.request(verb, "/api/a")
                httpx.request(method=verb, url="/api/b")
                taskqueue.add(url="/task/c", method=verb)
            PY]);
        $endpoints = $this->endpoints($nodes);

        foreach (['ANY /api/a', 'ANY /api/b', 'ANY /task/c'] as $name) {
            assertArrayHasKey($name, $endpoints);
            assertSame('ANY', $endpoints[$name]['method']);
        }
    }

    public function testBlueprintsAndMountsNameTheRoutersTheyHangOn(): void
    {
        [$nodes, $edges] = $this->scanSource(['app/__init__.py' => '', 'app/web.py' => <<<'PY'
            from flask import Blueprint, Flask

            app = Flask(__name__)
            admin = Blueprint("admin", __name__, url_prefix="/admin")
            reports = Blueprint("reports", __name__)


            @admin.route("/users")
            def users() -> str:
                return "ok"


            admin.register_blueprint(reports, url_prefix="/reports")
            app.register_blueprint(admin, url_prefix="/v2")
            PY]);
        $routes = $this->routes($nodes);

        assertSame('py:router:app.web.admin', $routes['GET /admin/users']['router']);
        $mounts = [];
        foreach ($edges as $edge) {
            if ($edge->kind === 'mounts') {
                $mounts[$edge->targetReference] = $edge->attributes;
            }
        }
        assertSame(['app', '/v2'], [$mounts['py:router:app.web.admin']['onto'], $mounts['py:router:app.web.admin']['prefix']]);
        assertSame(['py:router:app.web.admin', '/reports'], [$mounts['py:router:app.web.reports']['onto'], $mounts['py:router:app.web.reports']['prefix']]);
    }

    public function testARouterBuiltInAFunctionOrHandedInHasAnUnknownPrefix(): void
    {
        [$nodes] = $this->scanSource(['app/__init__.py' => '', 'app/api.py' => <<<'PY'
            from fastapi import APIRouter, FastAPI

            app = FastAPI()
            router = APIRouter(prefix="/api")


            @router.get("/shared")
            def shared() -> str:
                return "ok"


            def build() -> APIRouter:
                router = APIRouter()

                @router.get("/local")
                def local() -> str:
                    return "ok"

                return router


            def register(handed: APIRouter, router: APIRouter) -> None:
                @handed.get("/handed")
                def handed_route() -> str:
                    return "ok"

                @router.get("/param")
                def param() -> str:
                    return "ok"


            @app.get("/direct")
            def direct() -> str:
                return "ok"
            PY]);
        $routes = $this->routes($nodes);

        assertSame('py:router:app.api.router', $routes['GET /api/shared']['router']);
        foreach (['GET /local', 'GET /handed', 'GET /param'] as $name) {
            assertArrayHasKey($name, $routes);
            assertSame('unknown', $routes[$name]['prefix'], $name);
            assertArrayNotHasKey('router', $routes[$name]);
        }
        assertArrayNotHasKey('prefix', $routes['GET /direct']);
    }

    public function testAnAddUrlRuleOnAnUntypedOwnerHasAnUnknownPrefix(): void
    {
        [$nodes] = $this->scanSource(['app/__init__.py' => '', 'app/web.py' => <<<'PY'
            from flask import Flask

            app = Flask(__name__)


            def view() -> str:
                return "ok"


            def init(thing) -> None:
                thing.add_url_rule("/x", view_func=view)


            app.add_url_rule("/direct", view_func=view)
            PY]);
        $routes = $this->routes($nodes);

        assertSame('unknown', $routes['GET /x']['prefix']);
        assertArrayNotHasKey('router', $routes['GET /x']);
        assertArrayNotHasKey('prefix', $routes['GET /direct']);
    }

    public function testAMountOntoAnUnknownReceiverHasNoOnto(): void
    {
        [, $edges] = $this->scanSource(['app/__init__.py' => '', 'app/api.py' => <<<'PY'
            from fastapi import APIRouter

            child = APIRouter(prefix="/child")


            def attach(parent: APIRouter) -> None:
                parent.include_router(child, prefix="/v1")
            PY]);
        $mounts = array_values(array_filter($edges, static fn(EdgeFact $edge): bool => $edge->kind === 'mounts'));

        assertSame(1, count($mounts));
        assertArrayNotHasKey('onto', $mounts[0]->attributes);
        assertSame('/v1', $mounts[0]->attributes['prefix']);
    }

    public function testCallsThatNameNoRequestedUrlMakeNoEndpoint(): void
    {
        [$nodes] = $this->scanSource(['app/__init__.py' => '', 'app/clients.py' => <<<'PY'
            import requests
            from google.appengine.api import taskqueue


            def calls(target: str) -> None:
                requests.request(url="/no-method")
                requests.Session()
                taskqueue.add(url=target)
                requests.get("/kept")
            PY]);

        assertSame(['GET /kept'], array_keys($this->endpoints($nodes)));
    }

    public function testAnAppRebuiltAsARouterIsNoLongerAnApp(): void
    {
        [$nodes] = $this->scanSource(['app/__init__.py' => '', 'app/api.py' => <<<'PY'
            from fastapi import APIRouter, FastAPI

            app = FastAPI()
            app = APIRouter(prefix="/v1")


            @app.get("/items")
            def items() -> str:
                return "ok"
            PY]);
        $routes = $this->routes($nodes);

        assertSame('py:router:app.api.app', $routes['GET /v1/items']['router']);
    }

    public function testADjangoRePathRouteIsMarkedAsAPattern(): void
    {
        [$nodes] = $this->scanSource(['app/__init__.py' => '', 'app/urls.py' => <<<'PY'
            from django.urls import path, re_path
            from . import views

            urlpatterns = [
                path("plain/", views.plain),
                re_path(r"^items/(?P<pk>[0-9]+)/$", views.item),
            ]
            PY, 'app/views.py' => <<<'PY'
            def plain(request): ...
            def item(request, pk): ...
            PY]);
        $routes = $this->routes($nodes);

        assertSame(true, $routes['ANY /^items/(?P<pk>[0-9]+)/$']['regex']);
        assertArrayNotHasKey('regex', $routes['ANY /plain/']);
    }

    /**
     * @param array<string, string> $files
     * @return array{0: list<NodeFact>, 1: list<EdgeFact>}
     */
    private function scanSource(array $files): array
    {
        $root = sys_get_temp_dir() . '/knossos-stale-python-endpoints-' . bin2hex(random_bytes(4));
        foreach ($files as $path => $contents) {
            @mkdir(dirname($root . '/' . $path), 0o777, true);
            file_put_contents($root . '/' . $path, $contents);
        }
        try {
            $client = $this->pythonWorkerClient();
            $contributions = iterator_to_array($client->scan(['limits' => ScanLimits::DEFAULT, 'root' => $root, 'files' => array_keys($files)]));
            $client->shutdown();
        } finally {
            $this->removeTempTree($root);
        }

        return [
            array_merge(...array_map(fn(ScanContribution $item): array => $item->nodes, $contributions)),
            array_merge(...array_map(fn(ScanContribution $item): array => $item->edges, $contributions)),
        ];
    }

    /**
     * @param list<NodeFact> $nodes
     * @return array<string, array<string, mixed>>
     */
    private function routes(array $nodes): array
    {
        $routes = [];
        foreach ($nodes as $node) {
            if ($node->kind === 'route') {
                $routes[$node->displayName] = $node->attributes;
            }
        }

        return $routes;
    }

    /** @return array{0: list<NodeFact>, 1: list<EdgeFact>} */
    private function scan(): array
    {
        $client = $this->pythonWorkerClient();
        $contributions = iterator_to_array($client->scan(['limits' => ScanLimits::DEFAULT,
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
