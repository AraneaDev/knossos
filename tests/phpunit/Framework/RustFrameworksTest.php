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

final class RustFrameworksTest extends KnossosTestCase
{
    #[Group('rust-frameworks')]
    public function testRustFrameworkEnrichmentFlowsFromCargoToPersistedClassifications(): void
    {
        if (!is_file(self::rustWorkerBinary())) {
            self::markTestSkipped('The Rust worker binary is not built.');
        }
        $root = sys_get_temp_dir() . '/knossos-rust-frameworks-' . bin2hex(random_bytes(6));
        $database = tempnam(sys_get_temp_dir(), 'knossos-rust-frameworks-');
        if ($database === false) {
            throw new RuntimeException('Unable to allocate Rust framework database.');
        }
        mkdir($root . '/src', 0o700, true);
        file_put_contents($root . '/Cargo.toml', <<<'TOML'
[package]
name = "route-demo"
version = "0.1.0"

[dependencies]
axum = "0.7"
TOML);
        file_put_contents($root . '/src/lib.rs', <<<'RUST'
use axum::Router;
use axum::routing::get;

fn health() {}

fn app() -> Router {
    Router::new().route("/health", get(health))
}
RUST);

        try {
            $pdo = SqliteConnection::open($database);
            (new MigrationRunner($pdo, self::repositoryRoot() . '/migrations'))->migrate();
            $result = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root, new ScanOptions('Rust Frameworks'));

            assertSame(1, $result->data['parsed_files']);
            assertSame('1', (string) $pdo->query("SELECT COUNT(*) FROM nodes WHERE kind = 'package' AND canonical_name = 'route-demo'")->fetchColumn());
            assertSame('1', (string) $pdo->query("SELECT COUNT(*) FROM nodes WHERE kind = 'route' AND canonical_name = 'GET /health => crate::health'")->fetchColumn());
            assertSame('1', (string) $pdo->query("SELECT COUNT(*) FROM classifications WHERE role = 'rust.route_handler'")->fetchColumn());
            assertSame('axum', $pdo->query("SELECT json_extract(attributes_json, '$.framework') FROM nodes WHERE kind = 'route'")->fetchColumn());
            assertSame('1', (string) $pdo->query("SELECT COUNT(*) FROM edges WHERE kind = 'routes_to' AND target_id IN (SELECT id FROM nodes WHERE canonical_name = 'crate::health')")->fetchColumn());
        } finally {
            unset($pdo);
            @unlink($root . '/src/lib.rs');
            @unlink($root . '/Cargo.toml');
            @rmdir($root . '/src');
            @rmdir($root);
            foreach ([$database, $database . '-shm', $database . '-wal'] as $candidate) {
                @unlink($candidate);
            }
        }
    }

    #[Group('rust-frameworks')]
    public function testRustWorkerEmitsActixAndRocketRoutesWithGating(): void
    {
        if (!is_file(self::rustWorkerBinary())) {
            self::markTestSkipped('The Rust worker binary is not built.');
        }
        $root = sys_get_temp_dir() . '/knossos-rust-route-worker-' . bin2hex(random_bytes(6));
        mkdir($root . '/src', 0o700, true);
        file_put_contents($root . '/src/routes.rs', <<<'RUST'
#[get("/ping")]
fn ping() {}

#[get("/world")]
fn world() {}
RUST);

        $client = null;
        try {
            $client = $this->rustWorkerClient();
            $contributions = iterator_to_array($client->scan([
                'root' => $root,
                'files' => ['src/routes.rs'],
                'frameworks' => ['actix'],
            ]));

            $nodes = array_merge(...array_map(fn(ScanContribution $item): array => $item->nodes, $contributions));
            $routes = array_values(array_filter($nodes, fn(NodeFact $node): bool => $node->kind === 'route'));
            assertSame(2, count($routes));
            assertSame(['GET /ping => crate::routes::ping', 'GET /world => crate::routes::world'], array_map(fn(NodeFact $node): string => $node->canonicalName, $routes));
            $roles = array_values(array_filter($nodes, fn(NodeFact $node): bool => $node->kind === 'function'));
            foreach ($roles as $handler) {
                assertSame(['rust.route_handler'], $handler->attributes['rust_framework_roles']);
            }
            $edges = array_merge(...array_map(fn(ScanContribution $item): array => $item->edges, $contributions));
            assertSame(2, count(array_filter($edges, fn(EdgeFact $edge): bool => $edge->kind === 'routes_to')));
        } finally {
            // A worker that outlives a throwing scan() would linger for the
            // rest of the suite, so the shutdown belongs here, not on the
            // success path.
            $client?->shutdown();
            @unlink($root . '/src/routes.rs');
            @rmdir($root . '/src');
            @rmdir($root);
        }
    }

    /**
     * A route with a parameter is a template, kept as written: the handler
     * gets its route and role, and a caller's URL with a value in that place
     * binds to it.
     */
    #[Group('rust-frameworks')]
    public function testAnAxumRouteWithAParameterBindsACallersUrlToItsHandler(): void
    {
        if (!is_file(self::rustWorkerBinary())) {
            self::markTestSkipped('The Rust worker binary is not built.');
        }
        $root = sys_get_temp_dir() . '/knossos-stale-rust-template-' . bin2hex(random_bytes(6));
        mkdir($root . '/src', 0o700, true);
        mkdir($root . '/web', 0o700, true);
        file_put_contents($root . '/Cargo.toml', "[package]\nname = \"users\"\nversion = \"0.1.0\"\n\n[dependencies]\naxum = \"0.7\"\n");
        file_put_contents($root . '/src/lib.rs', "use axum::Router;\nuse axum::routing::get;\n\nfn user() {}\n\nfn app() -> Router {\n    Router::new().route(\"/users/:id\", get(user))\n}\n");
        file_put_contents($root . '/web/package.json', "{\"name\": \"web\", \"private\": true}\n");
        file_put_contents($root . '/web/api.js', 'export const load = (id) => fetch(`/users/${id}`);' . "\n");
        $pdo = $this->freshTestDatabase();
        try {
            (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);
            assertSame('1', (string) $pdo->query("SELECT COUNT(*) FROM nodes WHERE kind = 'route' AND canonical_name = 'GET /users/:id => crate::user'")->fetchColumn());
            assertSame('1', (string) $pdo->query("SELECT COUNT(*) FROM classifications c JOIN nodes n ON n.id = c.node_id WHERE c.role = 'rust.route_handler' AND n.canonical_name = 'crate::user'")->fetchColumn());
            $projectId = (string) $pdo->query('SELECT id FROM projects')->fetchColumn();
            $flow = \Knossos\Query\ArchitectureQueryService::forDatabase($pdo)->explainFlow($projectId, 'web/api.js#load', 'crate::user');
            assertSame(['calls_endpoint', 'resolves_to', 'routes_to'], array_column($flow->data['paths'][0]['hops'] ?? [], 'kind'));
        } finally {
            unset($pdo);
            $this->removeTempTree($root);
        }
    }

    /**
     * A router another function builds and nests is served under the nest's
     * prefix; a route on the router nothing nests keeps its own path.
     */
    #[Group('rust-frameworks')]
    public function testACallerReachesAHandlerOnANestedAxumRouter(): void
    {
        if (!is_file(self::rustWorkerBinary())) {
            self::markTestSkipped('The Rust worker binary is not built.');
        }
        $root = sys_get_temp_dir() . '/knossos-stale-rust-nest-' . bin2hex(random_bytes(6));
        mkdir($root . '/src', 0o700, true);
        mkdir($root . '/web', 0o700, true);
        file_put_contents($root . '/Cargo.toml', "[package]\nname = \"users\"\nversion = \"0.1.0\"\n\n[dependencies]\naxum = \"0.7\"\n");
        file_put_contents($root . '/src/lib.rs', "use axum::Router;\nuse axum::routing::get;\n\nfn user() {}\nfn health() {}\n\n"
            . "fn api() -> Router {\n    Router::new().route(\"/users/:id\", get(user))\n}\n\n"
            . "fn app() -> Router {\n    Router::new().route(\"/health\", get(health)).nest(\"/api\", api())\n}\n");
        file_put_contents($root . '/web/package.json', "{\"name\": \"web\", \"private\": true}\n");
        file_put_contents($root . '/web/api.js', 'export const load = (id) => fetch(`/api/users/${id}`);' . "\n"
            . "export const ping = () => fetch('/health');\n");
        $pdo = $this->freshTestDatabase();
        try {
            (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);
            $projectId = (string) $pdo->query('SELECT id FROM projects')->fetchColumn();
            $queries = \Knossos\Query\ArchitectureQueryService::forDatabase($pdo);
            $nested = $queries->explainFlow($projectId, 'web/api.js#load', 'crate::user')->data['paths'][0]['hops'] ?? [];
            assertSame(['calls_endpoint', 'resolves_to', 'routes_to'], array_column($nested, 'kind'));
            // The prefix is declared, so the match through the parameter is as good as one gets.
            assertSame(['probable', 'parameter'], [$nested[1]['confidence'], $nested[1]['attributes']['match']]);
            $top = $queries->explainFlow($projectId, 'web/api.js#ping', 'crate::health')->data['paths'][0]['hops'] ?? [];
            assertSame(['certain', 'exact'], [$top[1]['confidence'] ?? null, $top[1]['attributes']['match'] ?? null]);
        } finally {
            unset($pdo);
            $this->removeTempTree($root);
        }
    }
}
