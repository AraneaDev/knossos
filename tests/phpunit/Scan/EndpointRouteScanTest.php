<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\FileFanInQuery;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * Whole-project scans of small trees, for the cases where the join of an
 * endpoint to a route depends on what the scan keeps or leaves out.
 */
#[Group('scan')]
final class EndpointRouteScanTest extends KnossosTestCase
{
    private const SYMFONY_COMPOSER = '{"name":"x/symfony","require":{"php":"^8.3","symfony/framework-bundle":"^7.0"},"autoload":{"psr-4":{"App\\\\":"src/"}}}';

    public function testAnAnonymousClassInASymfonyControllerDoesNotFailTheScan(): void
    {
        [$pdo, $projectId, $root] = $this->scanFiles([
            'composer.json' => self::SYMFONY_COMPOSER,
            'src/Controller/OrderController.php' => <<<'PHP'
                <?php
                namespace App\Controller;

                use Symfony\Component\Routing\Attribute\Route;
                use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

                final class OrderController
                {
                    #[Route('/orders', name: 'orders')]
                    public function index() { return 1; }

                    public function build()
                    {
                        return new class {
                            public function link(UrlGeneratorInterface $g)
                            {
                                return $g->generate('orders');
                            }
                        };
                    }
                }
                PHP,
        ]);
        try {
            $count = $pdo->query("SELECT COUNT(*) FROM edges WHERE kind = 'resolves_to'")->fetchColumn();
            assertSame(1, (int) $count);
        } finally {
            $this->removeTempTree($root);
        }
    }

    public function testAClassLevelRouteNamePrefixJoinsTheNameAMethodRouteGets(): void
    {
        [$pdo, , $root] = $this->scanFiles([
            'composer.json' => self::SYMFONY_COMPOSER,
            'src/Controller/OrderController.php' => <<<'PHP'
                <?php
                namespace App\Controller;

                use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
                use Symfony\Component\Routing\Attribute\Route;

                #[Route('/orders', name: 'order_')]
                final class OrderController extends AbstractController
                {
                    #[Route('/{id}', name: 'show')]
                    public function show(int $id) { return $id; }

                    public function back()
                    {
                        return $this->redirectToRoute('order_show', ['id' => 1]);
                    }
                }
                PHP,
        ]);
        try {
            $rows = $pdo->query(<<<'SQL'
                SELECT e.confidence, e.attributes_json, t.canonical_name FROM edges e
                JOIN nodes t ON t.id = e.target_id WHERE e.kind = 'resolves_to'
                SQL)->fetchAll(\PDO::FETCH_ASSOC);
            assertSame(1, count($rows));
            assertSame('certain', $rows[0]['confidence']);
            assertSame('name', json_decode($rows[0]['attributes_json'], true)['match']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    public function testTwoCallersOfOneUrlDoNotDependOnEachOther(): void
    {
        [$pdo, $projectId, $root] = $this->scanFiles([
            'package.json' => '{"name":"x","dependencies":{}}',
            'src/a.ts' => "export function loadA() { return fetch('/api/orders'); }\n",
            'src/b.ts' => "export function loadB() { return fetch('/api/orders'); }\n",
        ]);
        try {
            $queries = ArchitectureQueryService::forDatabase($pdo);
            $impact = $queries->services()->changeQueries->changedFilesImpact($projectId, ['src/a.ts']);
            $reached = array_map(
                static fn(array $row): string => (string) $row['node']['canonical_name'],
                $impact->data['impacted_components'],
            );
            assertSame([], array_values(array_filter($reached, static fn(string $name): bool => str_contains($name, 'b.ts'))), implode(', ', $reached));

            $fanIn = (new FileFanInQuery($pdo))->forPaths($projectId, ['src/a.ts', 'src/b.ts']);
            assertSame(0, $fanIn['src/a.ts']['dependent_files']);
            assertSame(0, $fanIn['src/b.ts']['dependent_files']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    public function testASharedEndpointBelongsToNoPathBoundary(): void
    {
        [$pdo, $projectId, $root] = $this->scanFiles([
            'package.json' => '{"name":"x","dependencies":{}}',
            'shop/a.ts' => "export function loadA() { return fetch('/api/orders'); }\n",
            'admin/b.ts' => "export function loadB() { return fetch('/api/orders'); }\n",
        ]);
        try {
            $queries = ArchitectureQueryService::forDatabase($pdo);
            $result = $queries->services()->policyQueries->checkArchitecture($projectId, [
                ['id' => 'admin-not-shop', 'from_boundary' => 'module:admin', 'deny_targets' => ['module:shop']],
            ]);
            assertSame([], $result->data['violations'], json_encode($result->data['violations']));
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A policy judges one edge at a time, and the shared endpoint sits in no boundary, so the hop through it is no violation. */
    public function testAPolicyChecksDirectEdgesSoACallerIsNotFlaggedForTheRouteBehindAnEndpoint(): void
    {
        [$pdo, $projectId, $root] = $this->scanFiles([
            'pyproject.toml' => "[project]\nname = \"api\"\ndependencies = [\"fastapi\"]\n",
            'backend/__init__.py' => '',
            'backend/api.py' => "from fastapi import FastAPI\n\napp = FastAPI()\n\n\n@app.get('/api/orders')\ndef orders() -> list:\n    return []\n",
            'package.json' => '{"name":"web","dependencies":{}}',
            'shop/a.ts' => "export function loadA() { return fetch('/api/orders'); }\n",
            'admin/b.ts' => "export function loadB() { return fetch('/api/orders'); }\n",
        ]);
        try {
            $queries = ArchitectureQueryService::forDatabase($pdo);
            $result = $queries->services()->policyQueries->checkArchitecture($projectId, [
                ['id' => 'admin-not-backend', 'from_boundary' => 'module:admin', 'deny_targets' => ['python-package:backend']],
            ]);
            $sources = array_map(static fn(array $v): string => $v['source']['canonical_name'] . ' -> ' . $v['target']['canonical_name'], $result->data['violations']);
            assertSame([], $sources);
        } finally {
            $this->removeTempTree($root);
        }
    }

    public function testARouterBuiltInAFunctionGivesNoCertainLink(): void
    {
        [$pdo, , $root] = $this->scanFiles([
            'pyproject.toml' => "[project]\nname = \"api\"\ndependencies = [\"fastapi\"]\n",
            'backend/__init__.py' => '',
            'backend/api.py' => <<<'PY'
                from fastapi import APIRouter, FastAPI

                app = FastAPI()


                def build() -> APIRouter:
                    router = APIRouter(prefix="/api")

                    @router.get("/orders")
                    def orders() -> list:
                        return []

                    return router
                PY,
            'package.json' => '{"name":"web","dependencies":{}}',
            'src/api.ts' => "export const bare = () => fetch('/orders');\nexport const full = () => fetch('/api/orders');\n",
        ]);
        try {
            $rows = $pdo->query(<<<'SQL'
                SELECT s.canonical_name AS endpoint, e.confidence, e.attributes_json FROM edges e
                JOIN nodes s ON s.id = e.source_id WHERE e.kind = 'resolves_to'
                SQL)->fetchAll(\PDO::FETCH_ASSOC);
            $joined = array_column($rows, 'confidence', 'endpoint');
            assertSame(['GET /api/orders' => 'possible'], $joined);
            assertSame('suffix', json_decode($rows[0]['attributes_json'], true)['match']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * @param array<string, string> $files
     * @return array{0: \PDO, 1: string, 2: string}
     */
    private function scanFiles(array $files): array
    {
        $root = sys_get_temp_dir() . '/knossos-stale-endpoint-' . bin2hex(random_bytes(5));
        foreach ($files as $path => $contents) {
            @mkdir(dirname($root . '/' . $path), 0o777, true);
            file_put_contents($root . '/' . $path, $contents);
        }
        $pdo = $this->freshTestDatabase();
        try {
            $result = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);
        } catch (\Throwable $error) {
            $this->removeTempTree($root);
            throw $error;
        }

        return [$pdo, $result->projectId, $root];
    }
}
