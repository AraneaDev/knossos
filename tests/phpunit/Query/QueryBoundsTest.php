<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Maintenance\DatabaseMaintenanceService;
use Knossos\Mcp\NextStepPlanner;
use Knossos\Mcp\ResultEnricher;
use Knossos\Mcp\ToolCatalog;
use Knossos\Mcp\ToolService;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\QueryBounds;
use Knossos\Query\StalenessProbe;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/** Every whole-graph query accepts the same edge and node ceiling, and the catalogue advertises it. */
final class QueryBoundsTest extends KnossosTestCase
{
    #[Group('query')]
    public function testTheCatalogueAdvertisesTheSharedCeiling(): void
    {
        $schemas = array_column(ToolCatalog::definitions(), 'inputSchema', 'name');
        foreach (['dependency_cycles', 'architecture_health', 'check_architecture', 'suggest_location'] as $tool) {
            $properties = $schemas[$tool]['properties'] ?? [];
            self::assertSame(QueryBounds::MAX_EDGES, $properties['max_edges']['maximum'] ?? null, $tool . ' max_edges maximum');
            self::assertSame(QueryBounds::MAX_EDGES, $properties['max_edges']['default'] ?? null, $tool . ' max_edges default');
            if (isset($properties['max_nodes'])) {
                self::assertSame(QueryBounds::MAX_NODES, $properties['max_nodes']['maximum'], $tool . ' max_nodes maximum');
                self::assertSame(QueryBounds::MAX_NODES, $properties['max_nodes']['default'], $tool . ' max_nodes default');
            }
        }
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function edgeBoundedTools(): iterable
    {
        yield 'dependency_cycles' => ['dependency_cycles', []];
        yield 'architecture_health' => ['architecture_health', []];
        yield 'check_architecture' => ['check_architecture', ['policies' => []]];
        yield 'suggest_location' => ['suggest_location', ['feature_description' => 'checkout refunds']];
    }

    /** @param array<string, mixed> $arguments */
    #[Group('query')]
    #[DataProvider('edgeBoundedTools')]
    public function testEveryToolRefusesOneEdgePastTheCeiling(string $tool, array $arguments): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);

        $this->expectExceptionMessage((string) QueryBounds::MAX_EDGES);
        $this->tools($pdo)->call($tool, ['project_id' => $ids['project'], 'max_edges' => QueryBounds::MAX_EDGES + 1] + $arguments);
    }

    #[Group('query')]
    public function testACallAboveTheOldCapIsAccepted(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);

        $result = $this->tools($pdo)->call('dependency_cycles', ['project_id' => $ids['project'], 'max_edges' => 200_000, 'max_nodes' => 150_000]);

        self::assertSame(200_000, $result->data['bounds']['max_edges']);
        self::assertSame(150_000, $result->data['bounds']['max_nodes']);
    }

    #[Group('query')]
    public function testACallAboveTheCeilingIsRefusedNamingIt(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);

        $this->expectExceptionMessage((string) QueryBounds::MAX_EDGES);
        $this->tools($pdo)->call('dependency_cycles', ['project_id' => $ids['project'], 'max_edges' => QueryBounds::MAX_EDGES + 1]);
    }

    private function tools(PDO $pdo): ToolService
    {
        return new ToolService(
            new ProjectScanService($pdo, self::repositoryRoot(), [self::repositoryRoot() . '/tests/Fixtures/mixed']),
            ArchitectureQueryService::forDatabase($pdo),
            new DatabaseMaintenanceService($pdo, ':memory:'),
            new ResultEnricher(new StalenessProbe($pdo), new NextStepPlanner()),
        );
    }
}
