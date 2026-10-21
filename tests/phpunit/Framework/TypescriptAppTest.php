<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Framework;

use Knossos\Scan\ProjectScanService;
use Knossos\Scan\ScanOptions;
use Knossos\Scanner\Protocol\EdgeFact;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Store\MigrationRunner;
use Knossos\Store\SqliteConnection;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

final class TypescriptAppTest extends KnossosTestCase
{
    #[Group('typescript-app')]
    public function testTypescriptApplicationEnrichmentCoversNextReactVueStateAndClientEndpoints(): void
    {
        $root = self::repositoryRoot() . '/tests/Fixtures/typescript-app';
        $files = [
            'app/api/orders/route.ts',
            'app/layout.tsx',
            'app/page.tsx',
            'src/client.ts',
            'src/hooks.ts',
            'src/store.ts',
            'src/vue.ts',
        ];
        $client = $this->typescriptWorkerClient();
        $contributions = iterator_to_array($client->scan([
            'root' => $root,
            'files' => $files,
            'config_files' => ['tsconfig.json'],
        ]));
        $client->shutdown();
        $nodes = array_merge(...array_map(fn(ScanContribution $item): array => $item->nodes, $contributions));
        $edges = array_merge(...array_map(fn(ScanContribution $item): array => $item->edges, $contributions));
        $roles = [];
        foreach ($nodes as $node) {
            foreach ($node->attributes['typescript_framework_roles'] ?? [] as $role) {
                $roles[] = $role;
            }
        }
        foreach (['nextjs.layout', 'nextjs.page', 'nextjs.route_handler', 'nextjs.server_action', 'react.component', 'react.hook', 'state.store', 'vue.component', 'vue.composable'] as $role) {
            assertArrayContains($role, $roles);
        }
        $route = array_values(array_filter($nodes, fn(NodeFact $node): bool => $node->kind === 'route'))[0];
        assertSame('GET /api/orders => app/api/orders/route.ts#GET', $route->canonicalName);
        $endpoints = array_values(array_filter($nodes, fn(NodeFact $node): bool => $node->kind === 'endpoint'));
        assertSame(['GET /api/orders', 'POST /api/orders'], array_map(fn(NodeFact $node): string => $node->canonicalName, $endpoints));
        assertSame(1, count(array_filter($edges, fn(EdgeFact $edge): bool => $edge->kind === 'uses_hook')));
        assertSame(2, count(array_filter($edges, fn(EdgeFact $edge): bool => $edge->kind === 'calls_endpoint')));

        $database = tempnam(sys_get_temp_dir(), 'knossos-typescript-app-');
        if ($database === false) {
            throw new RuntimeException('Unable to allocate TypeScript app database.');
        }
        try {
            $pdo = SqliteConnection::open($database);
            (new MigrationRunner($pdo, self::repositoryRoot() . '/migrations'))->migrate();
            $result = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root, new ScanOptions('TypeScript App'));
            assertSame(7, $result->data['parsed_files']);
            assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM classifications WHERE role = 'nextjs.route_handler'")->fetchColumn());
            assertSame(2, (int) $pdo->query("SELECT COUNT(*) FROM classifications WHERE role = 'react.component'")->fetchColumn());
            assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM classifications WHERE role = 'vue.component'")->fetchColumn());
            assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM classifications WHERE role = 'state.store'")->fetchColumn());
        } finally {
            unset($pdo);
            foreach ([$database, $database . '-shm', $database . '-wal'] as $candidate) {
                @unlink($candidate);
            }
        }
    }

    /**
     * Next.js routes from the files under `app` (or `pages`): a `page.tsx`
     * anywhere else is an ordinary module, and naming it a page made it an
     * entry point no router calls.
     */
    #[Group('typescript-scanner')]
    public function testANextPageOrLayoutIsOneOnlyUnderTheRoutingFolder(): void
    {
        $root = sys_get_temp_dir() . '/knossos-next-pages-' . bin2hex(random_bytes(6));
        $files = [
            'tsconfig.json' => '{"compilerOptions":{"strict":false,"jsx":"react-jsx"},"include":["app","src"]}',
            'app/dashboard/page.tsx' => "export default function Dashboard() { return null; }\n",
            'src/components/page.tsx' => "export default function Page() { return null; }\n",
            'src/components/layout.tsx' => "export default function Layout() { return null; }\n",
        ];
        foreach ($files as $relative => $contents) {
            @mkdir(dirname($root . '/' . $relative), 0o755, true);
            file_put_contents($root . '/' . $relative, $contents);
        }
        try {
            $client = $this->typescriptWorkerClient();
            $contributions = iterator_to_array($client->scan([
                'root' => $root,
                'files' => ['app/dashboard/page.tsx', 'src/components/page.tsx', 'src/components/layout.tsx'],
                'config_files' => ['tsconfig.json'],
            ]));
            $client->shutdown();
        } finally {
            foreach (array_keys($files) as $relative) {
                @unlink($root . '/' . $relative);
            }
            foreach (['app/dashboard', 'app', 'src/components', 'src', ''] as $directory) {
                @rmdir($root . '/' . $directory);
            }
        }
        $roles = [];
        foreach (array_merge(...array_map(fn(ScanContribution $item): array => $item->nodes, $contributions)) as $node) {
            $roles[$node->canonicalName] = $node->attributes['typescript_framework_roles'] ?? [];
        }

        assertSame(true, in_array('nextjs.page', $roles['app/dashboard/page.tsx#Dashboard'] ?? [], true));
        assertSame([], array_values(array_filter($roles['src/components/page.tsx#Page'] ?? [], static fn(string $role): bool => str_starts_with($role, 'nextjs.'))));
        assertSame([], array_values(array_filter($roles['src/components/layout.tsx#Layout'] ?? [], static fn(string $role): bool => str_starts_with($role, 'nextjs.'))));
    }
}
