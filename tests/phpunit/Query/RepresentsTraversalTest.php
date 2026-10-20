<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\AbstractArchitectureQueryService;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\PathBetweenService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\Support\EnricherFlowScans;
use PDO;

use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertNotContains;
use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * A `represents` link joins an enriched record to the native symbol it stands
 * for: flow and impact cross it as an identity step, policies never judge it.
 * The enricher-flow fixture's Django view calls a backend helper, so a flow
 * can be seen continuing past the link into native code.
 */
#[Group('scan')]
final class RepresentsTraversalTest extends KnossosTestCase
{
    use EnricherFlowScans;

    private const COMPONENT = 'frontend/src/components/OrderList.vue';
    private const VIEW = 'backend.app.urls.search';
    private const HELPER = 'backend.app.search.search_orders';

    private PDO $pdo;
    private string $projectId = '';
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = $this->freshTestDatabase();
        $project = $this->enricherFlowProject();
        file_put_contents($project['root'] . '/knossos.json', json_encode([
            'version' => 1,
            'enrichers' => ['fixture'],
            'boundaries' => [['name' => 'Frontend', 'path_prefix' => 'frontend/'], ['name' => 'Backend', 'path_prefix' => 'backend/']],
        ]));
        $this->projectId = $this->scanEnricherFlow($this->pdo, $project)->projectId;
        $this->root = $project['root'];
    }

    protected function tearDown(): void
    {
        $this->removeEnricherFlowTrees();
        parent::tearDown();
    }

    public function testFlowCrossesRepresentsIntoNativeCalls(): void
    {
        $flow = $this->query()->explainFlow($this->projectId, self::COMPONENT, self::HELPER);

        $paths = $flow->data['paths'];
        assertNotSame([], $paths);
        $hops = $paths[0]['hops'];
        assertSame(['calls_endpoint', 'calls_endpoint', 'represents', 'calls'], array_column($hops, 'kind'));
        assertSame(['relation', 'relation', 'identity', 'relation'], array_column($hops, 'step'));
        assertSame(self::VIEW, $paths[0]['nodes'][3]['canonical_name']);
        assertSame(self::HELPER, $paths[0]['nodes'][4]['canonical_name']);
        foreach ($paths as $path) {
            assertSame(1, count(array_keys(array_column($path['hops'], 'step'), 'identity')), 'One identity step per path.');
        }
        $identity = $hops[2];
        assertSame('enricher', $identity['origin']);
        assertSame('fixture', $identity['attributes']['enricher']);
        assertSame('/task/search/ stands for search (identity, probable, enricher)', $identity['explanation']);
    }

    public function testImpactOnNativeMethodReachesEnrichedCaller(): void
    {
        $dependants = $this->dependants(self::VIEW);

        // The record that stands for the view, through the identity hop into it.
        $endpoint = $dependants['/task/search/'];
        assertSame(['ext:endpoint', 1, 'represents', 'identity', 'enricher'], [$endpoint['node']['kind'], $endpoint['distance'], $endpoint['via']['kind'], $endpoint['via']['step'], $endpoint['via']['origin']]);
        assertSame($this->idOf(self::VIEW), $endpoint['via']['target_id']);
        // Then the enriched caller of that record, and the native component behind it.
        $call = $dependants['GET /task/search/ from OrderList.vue'];
        assertSame(['ext:rest_call', 2, 'calls_endpoint', 'relation'], [$call['node']['kind'], $call['distance'], $call['via']['kind'], $call['via']['step']]);
        assertSame($endpoint['node']['id'], $call['via']['target_id']);
        $component = $dependants[self::COMPONENT];
        assertSame(['module', 3, 'calls_endpoint'], [$component['node']['kind'], $component['distance'], $component['via']['kind']]);
        assertSame($call['node']['id'], $component['via']['target_id']);
    }

    public function testImpactOnNativeHelperReachesTheRecordThroughTheView(): void
    {
        $dependants = $this->dependants(self::HELPER);

        $view = $dependants[self::VIEW];
        assertSame(['calls', 'relation'], [$view['via']['kind'], $view['via']['step']]);
        $endpoint = $dependants['/task/search/'];
        assertSame(['represents', 'identity'], [$endpoint['via']['kind'], $endpoint['via']['step']]);
        assertSame($view['node']['id'], $endpoint['via']['target_id']);
        assertSame($view['distance'] + 1, $endpoint['distance']);
    }

    public function testPaneRoutesCarryTheIdentityStep(): void
    {
        $routes = (new PathBetweenService($this->pdo))->routes($this->root, '/task/search/', self::HELPER);

        assertSame('ok', $routes['status']);
        $hops = $routes['routes'][0]['hops'];
        assertSame([['represents', 'identity', 'enricher', false], ['calls', 'relation', 'ast', false]], array_map(
            static fn(array $hop): array => [$hop['kind'], $hop['step'], $hop['origin'], $hop['derived']],
            $hops,
        ));
    }

    public function testPolicyChecksIgnoreRepresents(): void
    {
        assertNotContains('represents', AbstractArchitectureQueryService::POLICY_EDGE_KINDS);
        assertContains('represents', AbstractArchitectureQueryService::IMPACT_EDGE_KINDS);

        // A closed Backend boundary shows the check runs over the scanned graph at all.
        $result = $this->query()->checkArchitecture($this->projectId, [
            ['id' => 'frontend-no-backend', 'from_boundary' => 'Frontend', 'deny_targets' => ['Backend']],
            ['id' => 'backend-closed', 'from_boundary' => 'Backend', 'deny_targets' => ['Backend']],
        ]);

        $kinds = array_column(array_column($result->data['violations'], 'relationship'), 'kind');
        assertContains('calls', $kinds, 'The closed boundary does judge native calls inside it.');
        assertNotContains('represents', $kinds);
    }

    public function testUsagesShowEnricherOrigin(): void
    {
        $usages = $this->query()->listUsages($this->projectId, self::VIEW);

        $represents = array_values(array_filter($usages->data['usages'], static fn(array $usage): bool => $usage['kind'] === 'represents'));
        assertSame(1, count($represents));
        assertSame('enricher', $represents[0]['origin']);
        assertSame('fixture', $represents[0]['attributes']['enricher']);
    }

    public function testMinConfidenceCertainHidesEnrichedPath(): void
    {
        $flow = $this->query()->explainFlow($this->projectId, self::COMPONENT, self::HELPER, minConfidence: 'certain');

        assertSame([], $flow->data['paths']);
        // The enriched hops are probable, so a probable floor still finds the path.
        $probable = $this->query()->explainFlow($this->projectId, self::COMPONENT, self::HELPER, minConfidence: 'probable');
        assertSame(['calls_endpoint', 'calls_endpoint', 'represents', 'calls'], array_column($probable->data['paths'][0]['hops'], 'kind'));
    }

    /**
     * The impact dependants of `$symbol`, by canonical name.
     *
     * @return array<string, array<string, mixed>>
     */
    private function dependants(string $symbol): array
    {
        $dependants = [];
        foreach ($this->query()->impactAnalysis($this->projectId, $symbol)->data['dependants'] as $dependant) {
            $dependants[$dependant['node']['canonical_name']] = $dependant;
        }

        return $dependants;
    }

    private function idOf(string $canonicalName): string
    {
        $statement = $this->pdo->prepare('SELECT id FROM nodes WHERE canonical_name = ?');
        $statement->execute([$canonicalName]);

        return (string) $statement->fetchColumn();
    }

    private function query(): ArchitectureQueryService
    {
        return ArchitectureQueryService::forDatabase($this->pdo);
    }
}
