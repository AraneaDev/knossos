<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use InvalidArgumentException;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\DiagnosticsQueryService;
use Knossos\Result\ResultEnvelope;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\Support\EnricherFlowScans;
use PDO;

use function PHPUnit\Framework\assertArrayNotHasKey;
use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertGreaterThanOrEqual;
use function PHPUnit\Framework\assertIsString;
use function PHPUnit\Framework\assertNotSame;
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

    private const COMPONENT = 'frontend/src/components/OrderList.vue';
    private const VIEW = 'backend.app.urls.search';
    private const HELPER = 'backend.app.search.search_orders';
    private const EXPORT = 'export orders';
    private const GOLDEN = '/tests/Fixtures/flow-profiles/unprofiled-flows.json';

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

    /** Review focus 2: brand A, then the shared view, then a brand-B-only edge is never one path. */
    public function testBrandSwitchThroughSharedCodeIsNotReturned(): void
    {
        $this->scanWith('ok-profiles');

        $flow = $this->flow(self::COMPONENT, self::HELPER);

        $paths = $flow->data['paths'];
        assertNotSame([], $paths);
        $bindings = [];
        foreach ($paths as $path) {
            assertIsString($path['profile'], 'Every route here binds to exactly one brand.');
            $bindings[] = $path['profile'];
            $this->assertNoContradiction($path);
        }
        assertContains('brand_a', $bindings);
        assertContains('brand_b', $bindings);
        assertSame(['requested' => null], array_intersect_key($flow->data['profile_filter'], ['requested' => true]));
        assertGreaterThanOrEqual(1, $flow->data['profile_filter']['pruned_branches']);
        // The view's brand-B-only call is taken only by a path already bound to brand B.
        foreach ($paths as $path) {
            foreach ($path['hops'] as $hop) {
                if ($hop['kind'] === 'calls' && $hop['target_id'] === $this->idOf('GET /b/task/search/ from OrderList.vue')) {
                    assertSame('brand_b', $path['profile']);
                }
            }
        }
    }

    public function testPinnedProfileOnlyFollowsCompatibleEdges(): void
    {
        $this->scanWith('ok-profiles');

        $brandB = $this->flow(self::COMPONENT, self::HELPER, 'brand_b');
        $brandA = $this->flow(self::COMPONENT, self::HELPER, 'brand_a');

        assertNotSame([], $brandB->data['paths']);
        foreach ($brandB->data['paths'] as $path) {
            assertSame('brand_b', $path['profile']);
            foreach ($path['hops'] as $hop) {
                assertNotSame('brand_a', $hop['attributes']['profile'] ?? null, 'No brand-A-only edge on a brand-B path.');
            }
        }
        assertSame(['requested' => 'brand_b'], array_intersect_key($brandB->data['profile_filter'], ['requested' => true]));
        assertGreaterThanOrEqual(1, $brandB->data['profile_filter']['pruned_branches'], 'The brand-A rest call is cut off at once.');
        assertSame(['brand_a'], array_values(array_unique(array_column($brandA->data['paths'], 'profile'))));
        assertSame(
            [self::COMPONENT, 'GET /task/search/ from OrderList.vue', '/task/search/', self::VIEW, self::HELPER],
            array_column($brandA->data['paths'][0]['nodes'], 'canonical_name'),
        );
    }

    public function testUnprofiledHopsAreListed(): void
    {
        $this->scanWith('ok-profiles');

        $paths = $this->flow(self::COMPONENT, self::HELPER, 'brand_a')->data['paths'];

        assertCount(1, $paths);
        assertSame(['calls_endpoint', 'calls_endpoint', 'represents', 'calls'], array_column($paths[0]['hops'], 'kind'));
        // The identity hop and the view's native call carry no profile; the rest calls do.
        assertSame([2, 3], $paths[0]['unprofiled_hops']);
        assertSame('ast', $paths[0]['hops'][3]['origin']);
    }

    /** Review focus 3: two compatible routes over one node sequence are one path with both profiles. */
    public function testSameNodeSequenceUnionsProfiles(): void
    {
        $this->scanWith('ok-profiles');

        $paths = $this->flow(self::HELPER, self::EXPORT)->data['paths'];

        assertCount(1, $paths);
        assertSame([self::HELPER, self::EXPORT], array_column($paths[0]['nodes'], 'canonical_name'));
        assertSame(['brand_a', 'brand_b'], $paths[0]['profile']);
        assertSame([], $paths[0]['unprofiled_hops']);
    }

    public function testListEdgeIsCompatibleWithEitherPin(): void
    {
        $this->scanWith('ok-profiles');

        foreach (['brand_a', 'brand_b'] as $pin) {
            $direct = array_values(array_filter(
                $this->flow(self::VIEW, self::EXPORT, $pin)->data['paths'],
                static fn(array $path): bool => count($path['hops']) === 1,
            ));
            assertCount(1, $direct, $pin);
            assertSame('dispatches', $direct[0]['hops'][0]['kind']);
            assertSame(['brand_a', 'brand_b'], $direct[0]['hops'][0]['attributes']['profile']);
            assertSame($pin, $direct[0]['profile']);
        }
        $unpinned = $this->flow(self::VIEW, self::EXPORT)->data['paths'];
        assertSame(['brand_a', 'brand_b'], $unpinned[0]['profile']);
    }

    /** Review focus 1: a pin outside an edge's list cuts the branch off and counts it, it never throws. */
    public function testPinOutsideAListCutsTheBranchOff(): void
    {
        $this->scanWith('ok-profiles');

        $flow = $this->flow(self::VIEW, self::EXPORT, 'brand_c');

        assertSame([], $flow->data['paths']);
        assertSame(['requested' => 'brand_c', 'pruned_branches' => 4], $flow->data['profile_filter']);
    }

    /**
     * With no profiled edge and no pin the answer is the one this query gave
     * before profiles existed, plus the new fields: recorded in the golden
     * file from the code before this change, with ids normalised.
     */
    public function testNoProfilesLeavesOutputUnchanged(): void
    {
        $this->scanWith('ok');
        $query = ArchitectureQueryService::forDatabase($this->pdo);
        $flows = [
            'forward' => $query->explainFlow($this->projectId, self::COMPONENT, self::HELPER),
            'reversed' => $query->explainFlow($this->projectId, self::HELPER, self::COMPONENT, bothDirections: true),
        ];

        $actual = [];
        foreach ($flows as $name => $flow) {
            assertSame(['requested' => null, 'pruned_branches' => 0], $flow->data['profile_filter']);
            $data = $flow->data;
            unset($data['profile_filter']);
            foreach ($data['paths'] as $index => $path) {
                assertSame(null, $path['profile']);
                assertSame(array_keys($path['hops']), $path['unprofiled_hops']);
                unset($data['paths'][$index]['profile'], $data['paths'][$index]['unprofiled_hops']);
            }
            $actual[$name] = $this->normalised($flow->withData($data));
        }

        assertSame(json_decode((string) file_get_contents(self::repositoryRoot() . self::GOLDEN), true), $actual);
    }

    /** Review focus 5. */
    public function testInvalidProfileArgumentIsRejected(): void
    {
        $this->scanWith('ok-profiles');

        foreach (['bad value', '', str_repeat('a', 65), "brand_a\n"] as $profile) {
            $error = null;
            try {
                $this->flow(self::COMPONENT, self::HELPER, $profile);
            } catch (InvalidArgumentException $refused) {
                $error = $refused->getMessage();
            }
            assertSame('profile must be 1 to 64 letters, digits, "_", "." or "-".', $error, var_export($profile, true));
        }
    }

    public function testBothDirectionsCarriesItsOwnPrunedCount(): void
    {
        $this->scanWith('ok-profiles');
        $query = ArchitectureQueryService::forDatabase($this->pdo);

        $forward = $query->explainFlow($this->projectId, self::COMPONENT, self::HELPER, profile: 'brand_b');
        $reversed = $query->explainFlow($this->projectId, self::HELPER, self::COMPONENT, bothDirections: true, profile: 'brand_b');

        // The helper reaches nothing that leads back, so the answer is the forward search, run as the reverse direction.
        assertSame(true, $reversed->data['reversed']);
        assertSame($forward->data['profile_filter'], $reversed->data['profile_filter']);
        assertSame(array_column($forward->data['paths'], 'signature'), array_column($reversed->data['paths'], 'signature'));
        $outbound = $query->explainFlow($this->projectId, self::HELPER, self::COMPONENT, profile: 'brand_b');
        assertSame(['requested' => 'brand_b', 'pruned_branches' => 1], $outbound->data['profile_filter'], 'The helper\'s brand-A export call, counted in its own direction only.');
    }

    /**
     * No hop of the path carries a profile its binding excludes.
     *
     * @param array<string, mixed> $path
     */
    private function assertNoContradiction(array $path): void
    {
        $binding = (array) $path['profile'];
        foreach ($path['hops'] as $index => $hop) {
            $profile = $hop['attributes']['profile'] ?? null;
            if ($profile === null) {
                assertContains($index, $path['unprofiled_hops']);
                continue;
            }
            assertSame($binding, array_values(array_intersect($binding, (array) $profile)), 'Hop ' . $index . ' contradicts the binding.');
        }
    }

    private function flow(string $from, string $to, ?string $profile = null): ResultEnvelope
    {
        return ArchitectureQueryService::forDatabase($this->pdo)->explainFlow($this->projectId, $from, $to, profile: $profile);
    }

    /**
     * The envelope as JSON data, with every id this scan minted replaced by a stable name.
     *
     * @return array<string, mixed>
     */
    private function normalised(ResultEnvelope $flow): array
    {
        $names = [];
        foreach ($this->pdo->query('SELECT id, kind, canonical_name FROM nodes')->fetchAll(PDO::FETCH_NUM) as [$id, $kind, $name]) {
            $names[(string) $id] = '<node ' . $kind . ' ' . $name . '>';
        }
        foreach ($this->pdo->query('SELECT e.id, e.kind, s.canonical_name, t.canonical_name FROM edges e JOIN nodes s ON s.id = e.source_id JOIN nodes t ON t.id = e.target_id')->fetchAll(PDO::FETCH_NUM) as [$id, $kind, $source, $target]) {
            $names[(string) $id] = '<edge ' . $kind . ' ' . $source . ' > ' . $target . '>';
        }
        foreach ($this->pdo->query('SELECT id FROM scans')->fetchAll(PDO::FETCH_COLUMN) as $scan) {
            $names[(string) $scan] = '<scan>';
        }
        $names[$this->projectId] = '<project>';
        $json = strtr((string) json_encode($flow, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $names);

        return json_decode((string) preg_replace('/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})/', '<time>', $json), true);
    }

    private function idOf(string $canonicalName): string
    {
        $statement = $this->pdo->prepare('SELECT id FROM nodes WHERE canonical_name = ?');
        $statement->execute([$canonicalName]);

        return (string) $statement->fetchColumn();
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
