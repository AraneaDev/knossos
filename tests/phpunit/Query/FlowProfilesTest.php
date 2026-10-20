<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\DiagnosticsQueryService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\Support\EnricherFlowScans;
use PDO;

use function PHPUnit\Framework\assertArrayNotHasKey;
use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * Profile-compatible flow paths over the enricher-flow fixture, whose fixture
 * enricher models two brands of one Django/Vue application (`ok-profiles`):
 * a flow never composes edges whose profiles contradict, and says which
 * profile it assumed and which hops carried none.
 */
#[Group('scan')]
final class FlowProfilesTest extends KnossosTestCase
{
    use EnricherFlowScans;

    private PDO $pdo;
    private string $projectId = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = $this->freshTestDatabase();
    }

    protected function tearDown(): void
    {
        $this->removeEnricherFlowTrees();
        parent::tearDown();
    }

    public function testOkProfilesStoresEachEdgesProfile(): void
    {
        $this->scanWith('ok-profiles');

        assertSame([
            ['calls', '/b/task/search/', 'backend.app.search.search_orders', 'brand_b'],
            ['calls', 'backend.app.search.search_orders', 'export orders', 'brand_a'],
            ['calls', 'backend.app.urls.search', 'GET /b/task/search/ from OrderList.vue', 'brand_b'],
            ['calls_endpoint', 'GET /b/task/search/ from OrderList.vue', '/b/task/search/', 'brand_b'],
            ['calls_endpoint', 'GET /task/search/ from OrderList.vue', '/task/search/', 'brand_a'],
            ['calls_endpoint', 'frontend/src/components/OrderList.vue', 'GET /b/task/search/ from OrderList.vue', 'brand_b'],
            ['calls_endpoint', 'frontend/src/components/OrderList.vue', 'GET /task/search/ from OrderList.vue', 'brand_a'],
            ['dispatches', 'backend.app.search.search_orders', 'export orders', 'brand_b'],
            ['dispatches', 'backend.app.urls.search', 'export orders', ['brand_a', 'brand_b']],
            ['represents', '/b/task/search/', 'backend.app.urls.search', null],
            ['represents', '/task/search/', 'backend.app.urls.search', null],
        ], $this->enrichedEdges());
        assertSame([], array_values(array_filter($this->codes(), static fn(string $code): bool => str_starts_with($code, 'ENRICHER_'))));
    }

    public function testInvalidProfilesAreRemovedAndTheEdgesKept(): void
    {
        $this->scanWith('bad-profiles');

        $edges = $this->enrichedEdges();
        assertCount(4, $edges);
        foreach ($edges as $edge) {
            assertSame(null, $edge[3]);
        }
        $invalid = array_values(array_filter($this->diagnostics(), static fn(array $row): bool => $row['code'] === 'ENRICHER_PROFILE_INVALID'));
        assertCount(4, $invalid);
        foreach ($invalid as $diagnostic) {
            assertSame('warning', $diagnostic['severity']);
            assertSame(1, preg_match('/^Enricher fixture: \S+ edge from \S.* to \S.* kept without its profile: /', $diagnostic['message']), $diagnostic['message']);
        }
        assertArrayNotHasKey('profile', $this->attributesOf('represents'));
    }

    /** Scan a fresh copy of enricher-flow with the fixture enricher in `$mode`. */
    private function scanWith(string $mode): void
    {
        $project = $this->enricherFlowProject(mode: $mode);
        $this->projectId = $this->scanEnricherFlow($this->pdo, $project)->projectId;
    }

    /**
     * The enricher's stored edges: kind, source and target names, and the profile attribute if any.
     *
     * @return list<array{string, string, string, mixed}>
     */
    private function enrichedEdges(): array
    {
        $rows = $this->pdo->query(
            "SELECT e.kind, s.canonical_name, t.canonical_name, e.attributes_json FROM edges e
             JOIN nodes s ON s.id = e.source_id JOIN nodes t ON t.id = e.target_id
             WHERE e.owner_key = 'enricher:fixture' ORDER BY e.kind, s.canonical_name, t.canonical_name",
        )->fetchAll(PDO::FETCH_NUM);

        return array_map(static fn(array $row): array => [$row[0], $row[1], $row[2], json_decode((string) $row[3], true)['profile'] ?? null], $rows);
    }

    /** @return array<string, mixed> the attributes of the enricher's one edge of this kind */
    private function attributesOf(string $kind): array
    {
        $statement = $this->pdo->prepare("SELECT attributes_json FROM edges WHERE owner_key = 'enricher:fixture' AND kind = ?");
        $statement->execute([$kind]);

        return json_decode((string) $statement->fetchColumn(), true);
    }

    /** @return list<array<string, mixed>> */
    private function diagnostics(): array
    {
        return (new DiagnosticsQueryService($this->pdo))->listDiagnostics($this->projectId)->data['diagnostics'];
    }

    /** @return list<string> */
    private function codes(): array
    {
        return array_column($this->diagnostics(), 'code');
    }
}
