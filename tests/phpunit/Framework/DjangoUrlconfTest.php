<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Framework;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

use function PHPUnit\Framework\assertSame;

/**
 * A Django route's full URL is its own path behind every include() between
 * its urlconf and ROOT_URLCONF, and a DRF router's routes sit behind the
 * include() of its URLs. A caller's URL binds to the handler through them.
 */
final class DjangoUrlconfTest extends KnossosTestCase
{
    /**
     * @param array<string, string> $files path => contents, over the base project
     * @return array{0: PDO, 1: string, 2: string}
     */
    private function scanned(array $files = []): array
    {
        $root = sys_get_temp_dir() . '/knossos-stale-django-urlconf-' . bin2hex(random_bytes(6));
        $base = [
            'requirements.txt' => "django\ndjangorestframework\n",
            'proj/__init__.py' => '',
            'proj/settings.py' => "ROOT_URLCONF = 'proj.urls'\n",
            'proj/urls.py' => "from django.urls import include, path\n"
                . "from rest_framework.routers import DefaultRouter\n"
                . "from proj.views import NoteViewSet\n"
                . "router = DefaultRouter()\n"
                . "router.register('notes', NoteViewSet)\n"
                . "urlpatterns = [\n"
                . "    path('api/', include('shop.urls')),\n"
                . "    path('v2/', include(router.urls)),\n"
                . "]\n",
            'proj/views.py' => "class NoteViewSet:\n    pass\n",
            'shop/__init__.py' => '',
            'shop/urls.py' => "from django.urls import include, path\n"
                . "from shop.views import order\n"
                . "urlpatterns = [path('orders/<int:id>/', order), path('deep/', include('shop.deep.urls'))]\n",
            'shop/views.py' => "def order(request, id):\n    return None\n",
            'shop/deep/__init__.py' => '',
            'shop/deep/urls.py' => "from django.urls import path\n"
                . "from shop.views import order\n"
                . "urlpatterns = [path('items/', order)]\n",
            'web/package.json' => "{\"name\": \"web\", \"private\": true}\n",
            'web/api.js' => 'export const order = (id) => fetch(`/api/orders/${id}/`);' . "\n"
                . 'export const note = (id) => fetch(`/v2/notes/${id}/`);' . "\n"
                . 'export const items = () => fetch(`/api/deep/items/`);' . "\n",
        ];
        foreach ([...$base, ...$files] as $path => $contents) {
            if (!is_dir(dirname($root . '/' . $path))) {
                mkdir(dirname($root . '/' . $path), 0o700, true);
            }
            file_put_contents($root . '/' . $path, $contents);
        }
        $pdo = $this->freshTestDatabase();
        (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);

        return [$pdo, (string) $pdo->query('SELECT id FROM projects')->fetchColumn(), $root];
    }

    /** @return list<array<string, mixed>> */
    private static function hops(PDO $pdo, string $projectId, string $from, string $to): array
    {
        return ArchitectureQueryService::forDatabase($pdo)->explainFlow($projectId, $from, $to)->data['paths'][0]['hops'] ?? [];
    }

    #[Group('scan')]
    public function testACallerReachesAViewBehindAnInclude(): void
    {
        [$pdo, $projectId, $root] = $this->scanned();
        try {
            $hops = self::hops($pdo, $projectId, 'web/api.js#order', 'shop.views.order');
            assertSame(['calls_endpoint', 'resolves_to', 'routes_to'], array_column($hops, 'kind'));
            // The prefix is declared all the way up to ROOT_URLCONF; a match through a parameter is probable at best.
            assertSame(['probable', 'parameter'], [$hops[1]['confidence'], $hops[1]['attributes']['match']]);
            $nested = self::hops($pdo, $projectId, 'web/api.js#items', 'shop.views.order');
            assertSame(['calls_endpoint', 'resolves_to', 'routes_to'], array_column($nested, 'kind'));
            // Two includes deep, every segment literal: an exact match.
            assertSame(['certain', 'exact'], [$nested[1]['confidence'], $nested[1]['attributes']['match']]);
            // An include is no route of its own.
            assertSame('0', (string) $pdo->query("SELECT COUNT(*) FROM nodes WHERE kind = 'route' AND canonical_name LIKE '%=> dynamic'")->fetchColumn());
        } finally {
            unset($pdo);
            $this->removeTempTree($root);
        }
    }

    #[Group('scan')]
    public function testACallerReachesAViewSetBehindTheIncludeOfItsRouter(): void
    {
        [$pdo, $projectId, $root] = $this->scanned();
        try {
            $hops = self::hops($pdo, $projectId, 'web/api.js#note', 'proj.views.NoteViewSet');
            assertSame(['calls_endpoint', 'resolves_to', 'routes_to'], array_column($hops, 'kind'));
            assertSame(['probable', 'parameter'], [$hops[1]['confidence'], $hops[1]['attributes']['match']]);
        } finally {
            unset($pdo);
            $this->removeTempTree($root);
        }
    }

    #[Group('scan')]
    public function testAUrlconfNothingIncludesHasAnUnknownPrefix(): void
    {
        [$pdo, $projectId, $root] = $this->scanned([
            'proj/urls.py' => "from django.urls import path\nurlpatterns = []\n",
        ]);
        try {
            // shop.urls is now included by nothing: the route may sit behind any prefix, so the join is a guess at best.
            $hops = self::hops($pdo, $projectId, 'web/api.js#order', 'shop.views.order');
            assertSame('possible', $hops[1]['confidence'] ?? null);
        } finally {
            unset($pdo);
            $this->removeTempTree($root);
        }
    }
}
