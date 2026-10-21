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

final class NestJsTest extends KnossosTestCase
{
    #[Group('nestjs')]
    public function testNestjsEnricherExtractsDecoratorRolesModulesAndRoutesWithoutBootingTheApp(): void
    {
        $root = self::repositoryRoot() . '/tests/Fixtures/nest';
        $files = ['src/app.module.ts', 'src/cats.controller.ts', 'src/cats.service.ts'];
        $client = $this->typescriptWorkerClient();
        $contributions = iterator_to_array($client->scan(['root' => $root, 'files' => $files, 'config_files' => ['tsconfig.json']]));
        $client->shutdown();
        $nodes = array_merge(...array_map(fn(ScanContribution $item): array => $item->nodes, $contributions));
        $edges = array_merge(...array_map(fn(ScanContribution $item): array => $item->edges, $contributions));
        $roles = [];
        foreach ($nodes as $node) {
            foreach ($node->attributes['nestjs_roles'] ?? [] as $role) {
                $roles[] = $role;
            }
        }
        sort($roles);
        assertSame(['nestjs.controller', 'nestjs.module', 'nestjs.provider'], $roles);
        $routes = array_values(array_filter($nodes, fn(NodeFact $node): bool => $node->kind === 'route'));
        assertSame(2, count($routes));
        assertSame(['GET /cats', 'POST /cats/adopt'], array_column($routes, 'displayName'));
        assertSame(2, count(array_filter($edges, fn(EdgeFact $edge): bool => $edge->kind === 'routes_to')));
        assertSame(2, count(array_filter($edges, fn(EdgeFact $edge): bool => in_array($edge->attributes['nestjs_module_field'] ?? null, ['controllers', 'providers'], true))));
        assertSame(1, count(array_filter($edges, fn(EdgeFact $edge): bool => ($edge->attributes['nestjs_module_field'] ?? null) === 'exports')));

        $database = tempnam(sys_get_temp_dir(), 'knossos-nest-');
        if ($database === false) {
            throw new RuntimeException('Unable to allocate NestJS database.');
        }
        try {
            $pdo = SqliteConnection::open($database);
            (new MigrationRunner($pdo, self::repositoryRoot() . '/migrations'))->migrate();
            $scan = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root, new ScanOptions('Nest Fixture'));
            assertSame(2, (int) $pdo->query("SELECT COUNT(*) FROM nodes WHERE kind = 'route'")->fetchColumn());
            assertSame(3, (int) $pdo->query("SELECT COUNT(*) FROM classifications WHERE role LIKE 'nestjs.%'")->fetchColumn());
            assertSame(2, (int) $pdo->query("SELECT COUNT(*) FROM edges WHERE kind = 'routes_to'")->fetchColumn());
            assertSame(true, $scan->data['nodes'] >= 10);
        } finally {
            unset($pdo);
            foreach ([$database, $database . '-shm', $database . '-wal'] as $candidate) {
                if (is_file($candidate)) {
                    unlink($candidate);
                }
            }
        }
    }

    /**
     * The global prefix main.ts sets goes in front of every route but the
     * excluded ones, and the controllers may import their decorators any way.
     */
    #[Group('nestjs')]
    public function testACallerReachesAControllerBehindTheGlobalPrefix(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-nest-prefix-' . bin2hex(random_bytes(6));
        $files = [
            'package.json' => "{\"name\": \"api\", \"private\": true, \"dependencies\": {\"@nestjs/common\": \"10.0.0\", \"@nestjs/core\": \"10.0.0\"}}\n",
            'src/main.ts' => "import { NestFactory } from '@nestjs/core';\nimport { AppModule } from './app.module';\n"
                . "async function bootstrap(): Promise<void> {\n    const app = await NestFactory.create(AppModule);\n"
                . "    app.setGlobalPrefix('api', { exclude: ['health'] });\n}\nvoid bootstrap();\n",
            'src/app.module.ts' => "export class AppModule {}\n",
            'src/users.controller.ts' => "import * as nest from '@nestjs/common';\n@nest.Controller('users')\n"
                . "export class UsersController {\n    @nest.Get(':id') show(): string { return ''; }\n}\n",
            'src/health.controller.ts' => "import { Controller, Get } from '@nestjs/common';\n@Controller('health')\n"
                . "export class HealthController {\n    @Get() check(): string { return ''; }\n}\n",
            'src/client.ts' => 'export const load = (id: string) => fetch(`/api/users/${id}`);' . "\n"
                . "export const ping = () => fetch('/health');\n",
        ];
        foreach ($files as $path => $contents) {
            if (!is_dir(dirname($root . '/' . $path))) {
                mkdir(dirname($root . '/' . $path), 0o700, true);
            }
            file_put_contents($root . '/' . $path, $contents);
        }
        $pdo = $this->freshTestDatabase();
        try {
            (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);
            $projectId = (string) $pdo->query('SELECT id FROM projects')->fetchColumn();
            $queries = \Knossos\Query\ArchitectureQueryService::forDatabase($pdo);
            $load = $queries->explainFlow($projectId, 'src/client.ts#load', 'src/users.controller.ts#UsersController::show')->data['paths'][0]['hops'] ?? [];
            assertSame(['calls_endpoint', 'resolves_to', 'routes_to'], array_column($load, 'kind'));
            assertSame(['probable', 'parameter'], [$load[1]['confidence'], $load[1]['attributes']['match']]);
            $ping = $queries->explainFlow($projectId, 'src/client.ts#ping', 'src/health.controller.ts#HealthController::check')->data['paths'][0]['hops'] ?? [];
            // An excluded path is served without the prefix.
            assertSame(['certain', 'exact'], [$ping[1]['confidence'] ?? null, $ping[1]['attributes']['match'] ?? null]);
        } finally {
            unset($pdo);
            $this->removeTempTree($root);
        }
    }

    /**
     * What a setGlobalPrefix call reports: its literal prefix and excluded paths in
     * either form, null for a prefix that is not a literal, and nothing in a file
     * that does not import NestJS.
     */
    #[Group('nestjs')]
    public function testTheGlobalPrefixIsReportedAsWrittenOrAsUnknown(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-nest-settings-' . bin2hex(random_bytes(6));
        $files = [
            'src/main.ts' => "import { NestFactory } from '@nestjs/core';\ndeclare const AppModule: object;\n"
                . "async function bootstrap(): Promise<void> {\n    const app = await NestFactory.create(AppModule);\n"
                . "    app.setGlobalPrefix('api', { exclude: ['health', { path: 'metrics', method: 0 }, dynamicPath] });\n}\n"
                . "declare const dynamicPath: string;\n",
            'src/other.ts' => "import * as nest from '@nestjs/core';\ndeclare const prefix: string;\n"
                . "export async function start(): Promise<void> {\n    const app = await nest.NestFactory.create(Object);\n    app.setGlobalPrefix(prefix);\n}\n",
            'src/plain.ts' => "declare const server: { setGlobalPrefix(prefix: string): void };\nserver.setGlobalPrefix('v1');\n",
            'src/jwt.strategy.ts' => "import { PassportStrategy } from '@nestjs/passport';\ndeclare const Strategy: new () => object;\n"
                . "export class JwtStrategy extends PassportStrategy(Strategy) {\n    validate(): object { return {}; }\n    other(): void {}\n}\n"
                . "export class Plain extends Object {\n    validate(): void {}\n}\n",
        ];
        foreach ($files as $path => $contents) {
            if (!is_dir(dirname($root . '/' . $path))) {
                mkdir(dirname($root . '/' . $path), 0o700, true);
            }
            file_put_contents($root . '/' . $path, $contents);
        }
        try {
            $client = $this->typescriptWorkerClient();
            $contributions = iterator_to_array($client->scan(['root' => $root, 'files' => array_keys($files)]));
            $client->shutdown();
            $settings = [];
            foreach ($contributions as $contribution) {
                foreach ($contribution->nodes as $node) {
                    if ($node->kind === 'setting') {
                        $settings[$node->canonicalName] = [$node->attributes['global_prefix'] ?? null, $node->attributes['exclude'] ?? null];
                    }
                }
            }
            ksort($settings);
            $handlers = [];
            foreach ($contributions as $contribution) {
                foreach ($contribution->nodes as $node) {
                    if (in_array('nestjs.framework_handler', $node->attributes['nestjs_roles'] ?? [], true)) {
                        $handlers[] = $node->canonicalName;
                    }
                }
            }
            // Passport calls a strategy's validate(); nothing calls another class's.
            assertSame(['src/jwt.strategy.ts#JwtStrategy::validate'], $handlers);
            assertSame([
                'src/main.ts#setGlobalPrefix' => ['api', ['health', 'metrics']],
                'src/other.ts#setGlobalPrefix' => [null, []],
            ], $settings);
        } finally {
            $this->removeTempTree($root);
        }
    }
}
