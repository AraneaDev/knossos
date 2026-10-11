<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\BlastRadiusService;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

use function PHPUnit\Framework\assertSame;

/**
 * Whether a ring's component is tested does not depend on how far out the
 * rings stop: a component at the edge of the search is reached by its test
 * however many hops the test stands beyond that edge.
 */
final class BlastRadiusTestReachTest extends KnossosTestCase
{
    /**
     * `App\Checkout` with a chain of dependents C1 (one hop) to C5, D2 (six
     * hops, the last ring hop) calling C5, D1 calling D2, and a test calling
     * D1: the test stands eight hops out, past the rings, yet it exercises
     * every component in them.
     */
    #[Group('query')]
    public function testAComponentWhoseTestStandsPastTheLastRingIsStillTested(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $owner = 'php:file:src/Checkout.php';
        $node = function (string $name, string $path, bool $test = false) use ($repository, $ids, $owner): string {
            $file = StableId::file($ids['project'], $path);
            $repository->saveFile($file, $ids['project'], $path, hash('sha256', $path), 20, 1, 'php', '0.1.0', $ids['scan']);
            $id = StableId::symbol($ids['project'], 'php', 'class', $name);
            $repository->saveNode($id, $ids['project'], 'php', 'class', $name, substr((string) strrchr('\\' . $name, '\\'), 1), null, $file, 1, 10, 'ast', 'certain', [], $owner, $ids['scan']);
            if ($test) {
                $repository->saveClassification(StableId::classification($ids['project'], $id, 'quality.test_module', 'core.test.modules.v1'), $ids['project'], $id, 'quality.test_module', 'derived', 'probable', 'core.test.modules.v1', $file, 1, 10, [], $ids['scan']);
            }
            return $id;
        };
        $calls = function (string $from, string $to, string $at) use ($repository, $ids, $owner): void {
            $repository->saveEdge(StableId::edge($ids['project'], 'calls', $from, $to, $at), $ids['project'], 'calls', $from, $to, null, 1, 1, 'ast', 'certain', [], $owner, $ids['scan']);
        };
        $previous = $ids['checkout'];
        foreach ([1, 2, 3, 4, 5] as $k) {
            $current = $node("App\\C{$k}", "src/C{$k}.php");
            $calls($current, $previous, "src/C{$k}.php:5");
            $previous = $current;
        }
        $d2 = $node('App\\D2', 'src/D2.php');
        $calls($d2, $previous, 'src/D2.php:5');
        $d1 = $node('App\\D1', 'src/D1.php');
        $calls($d1, $d2, 'src/D1.php:5');
        $test = $node('Tests\\ChainTest', 'tests/ChainTest.php', test: true);
        $calls($test, $d1, 'tests/ChainTest.php:5');
        $repository->completeScan($ids['project'], $ids['scan']);

        $rings = (new BlastRadiusService($pdo))->rings('/workspace/fixture-shop', 'App\\Checkout');

        assertSame('ok', $rings['status']);
        [$one, $two, $far] = $rings['rings'];
        assertSame([1, 1], [$one['count'], $one['tested']]);
        assertSame([1, 1], [$two['count'], $two['tested']]);
        // C3, C4, C5 and D2: D1 stands past the last ring hop and is no member.
        assertSame(['App\\C3', 'App\\C4', 'App\\C5', 'App\\D2'], array_column($far['items'], 'canonical_name'));
        assertSame(4, $far['tested']);
        assertSame([['path' => 'tests/ChainTest.php', 'hop' => 8]], $far['tests']['items']);
        // More depends on the rings than they hold: D1 and the test past the last hop.
        assertSame(true, $rings['truncated']);
    }
}
