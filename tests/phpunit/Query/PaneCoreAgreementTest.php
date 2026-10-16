<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\BlastRadiusService;
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
}
