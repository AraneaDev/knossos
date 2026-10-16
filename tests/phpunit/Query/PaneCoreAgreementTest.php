<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\BlastRadiusService;
use Knossos\Query\FlowQuery;
use Knossos\Query\GraphSearchService;
use Knossos\Query\PathBetweenService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\Support\PaneAgreementGraph;
use PHPUnit\Framework\Attributes\Group;

use function PHPUnit\Framework\assertSame;

/**
 * The person in the pane and the agent through MCP ask the same questions:
 * what depends on this, how does this reach that, where is this. Each pair
 * of answers is compared here on one graph, so the pane can never drift
 * from the core queries again.
 */
final class PaneCoreAgreementTest extends KnossosTestCase
{
    use PaneAgreementGraph;

    /**
     * Every non-test dependant of `App\Target`, by ring, is the same in the
     * pane's rings and in `impact_analysis`: the caller reaching the class
     * only through its static method included.
     */
    #[Group('query')]
    public function testRingsAgreeWithImpactAnalysis(): void
    {
        [$pdo, $root, $projectId] = $this->paneAgreementGraph();

        $rings = (new BlastRadiusService($pdo))->rings($root, 'App\\Target');
        $fromRings = [];
        foreach ($rings['rings'] as $ring) {
            $fromRings[$ring['hop']] = array_column($ring['items'], 'canonical_name');
            sort($fromRings[$ring['hop']]);
        }

        $impact = ArchitectureQueryService::forDatabase($pdo)->impactAnalysis($projectId, 'App\\Target', 6, 1500, timeoutMs: 2000)->data;
        $tests = self::idsOfTests($pdo);
        $fromImpact = [1 => [], 2 => [], 3 => []];
        foreach ($impact['dependants'] as $row) {
            if (!isset($tests[$row['node']['id']])) {
                $fromImpact[min(3, $row['distance'])][] = $row['node']['canonical_name'];
            }
        }
        foreach ($fromImpact as &$names) {
            sort($names);
        }
        unset($names);

        assertSame($fromImpact, $fromRings);
        assertSame(['App\\Maybe', 'App\\StaticCaller', 'App\\User'], $fromRings[1]);
    }

    /**
     * The pane's routes are explain_flow's, hop for hop: the names, the
     * confidences, and the derived `implemented_by` step from the interface
     * method to the one implementing it, marked as derived.
     */
    #[Group('query')]
    public function testRoutesAgreeWithExplainFlow(): void
    {
        [$pdo, $root, $projectId] = $this->paneAgreementGraph();

        $pane = (new PathBetweenService($pdo))->routes($root, 'App\\User', 'App\\Adapter::run');
        $core = ArchitectureQueryService::forDatabase($pdo)->explainFlow($projectId, 'App\\User', 'App\\Adapter::run')->data;

        assertSame(false, $pane['reversed']);
        assertSame(self::coreRoutes($core), self::paneRoutes($pane));
        $last = end($pane['routes'][0]['hops']);
        assertSame(['implemented_by', 'probable', true], [$last['kind'], $last['confidence'], $last['derived']]);
    }

    /**
     * Asked the wrong way round, the pane shows the routes the other way, as
     * explain_flow does when asked for both directions.
     */
    #[Group('query')]
    public function testAReversedRouteAgreesWithExplainFlowBothWays(): void
    {
        [$pdo, $root, $projectId] = $this->paneAgreementGraph();

        $pane = (new PathBetweenService($pdo))->routes($root, 'App\\Adapter::run', 'App\\User');
        $core = ArchitectureQueryService::forDatabase($pdo)->explainFlow($projectId, 'App\\Adapter::run', 'App\\User', bothDirections: true)->data;

        assertSame([true, true], [$pane['reversed'], $core['reversed']]);
        assertSame(['App\\Adapter::run', 'App\\User'], [$pane['from']['canonical_name'], $pane['to']['canonical_name']]);
        assertSame(self::coreRoutes($core), self::paneRoutes($pane));
    }

    /**
     * @param array<string, mixed> $flow explain_flow's data
     * @return list<array{nodes: list<string>, confidences: list<string>}>
     */
    private static function coreRoutes(array $flow): array
    {
        return array_map(static fn(array $route): array => [
            'nodes' => array_map(static fn(array $node): string => (string) $node['canonical_name'], $route['nodes']),
            'confidences' => array_map(static fn(array $hop): string => (string) $hop['confidence'], $route['hops']),
        ], $flow['paths']);
    }

    /**
     * @param array<string, mixed> $routes the pane's answer
     * @return list<array{nodes: list<string>, confidences: list<string>}>
     */
    private static function paneRoutes(array $routes): array
    {
        return array_map(static fn(array $route): array => [
            'nodes' => array_column($route['nodes'], 'canonical_name'),
            'confidences' => array_column($route['hops'], 'confidence'),
        ], $routes['routes']);
    }

    /**
     * The finder's components are search_architecture's, in its order, for a
     * name, for two words, and for a word only a note holds; a note on a name
     * no component holds is listed apart, under `notes`.
     */
    #[Group('query')]
    public function testFinderAgreesWithSearchArchitecture(): void
    {
        [$pdo, $root, $projectId] = $this->paneAgreementGraph();
        $finder = new GraphSearchService($pdo);
        $core = ArchitectureQueryService::forDatabase($pdo);

        foreach (['target', 'static caller', 'zebrafish'] as $query) {
            $found = $finder->search($root, $query)['results'];
            $expected = array_column($core->searchArchitecture($projectId, $query)->data['results'], 'canonical_name');
            $components = array_column(array_filter($found, static fn(array $row): bool => $row['type'] === 'component'), 'canonical_name');
            assertSame($expected, array_slice($components, 0, count($expected)), $query);
        }
        $notes = $finder->search($root, 'zebrafish')['notes'];
        assertSame([['App\\Gone', 'model', 'missing']], array_map(static fn(array $row): array => [$row['canonical_name'], $row['author'], $row['component_status']], $notes));
        assertSame('Replaced by the zebrafish adapter.', $notes[0]['text']);
    }

    /**
     * A forward search the time cut is no answer that only the other way
     * joins the two: the core does not reverse it, so neither does the pane.
     * The clock jumps past the forward search's deadline once it is set.
     */
    #[Group('query')]
    public function testAForwardSearchTheTimeCutIsNotReversed(): void
    {
        [$pdo, , $projectId] = $this->paneAgreementGraph();
        $reads = 0;
        $clock = static function () use (&$reads): int {
            return $reads++ === 0 ? 0 : 1_000_000_000_000_000;
        };

        $flow = (new FlowQuery($pdo, $clock))->explainFlow($projectId, 'App\\Adapter::run', 'App\\User', bothDirections: true);

        assertSame([false, [], true], [$flow->data['reversed'], $flow->data['paths'], $flow->truncated]);
        assertSame(['time_limit'], $flow->data['bounds']['truncation_reasons']);
    }
}
