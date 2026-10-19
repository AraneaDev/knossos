<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\BlastRadiusService;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

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
        // Truncated is the core's: a bound cut the walk. Stopping at the last ring is no cut, though D1 and the
        // test stand past it.
        assertSame([false, []], [$rings['truncated'], $rings['truncation_reasons']]);
    }

    /**
     * A hub's dependents can fill the search before its tests are read: a
     * component whose test the cut-off left unread is still tested, and the
     * ring names that test. `App\Checkout` < C1 < C2 < the test, with room
     * for two dependents: C1 and C2 fit, the test does not.
     */
    #[Group('query')]
    public function testAComponentWhoseTestTheDependentsBoundCutOffIsStillTested(): void
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
        $c1 = $node('App\\C1', 'src/C1.php');
        $calls($c1, $ids['checkout'], 'src/C1.php:5');
        $c2 = $node('App\\C2', 'src/C2.php');
        $calls($c2, $c1, 'src/C2.php:5');
        $test = $node('Tests\\ChainTest', 'tests/ChainTest.php', test: true);
        $calls($test, $c2, 'tests/ChainTest.php:5');
        $repository->completeScan($ids['project'], $ids['scan']);

        $rings = (new BlastRadiusService($pdo, dependents: 2))->rings('/workspace/fixture-shop', 'App\\Checkout');

        assertSame(true, $rings['truncated']);
        [$one, $two] = $rings['rings'];
        assertSame([1, 1], [$one['count'], $one['tested']]);
        assertSame([1, 1], [$two['count'], $two['tested']]);
        assertSame([true], array_column($two['items'], 'tested'));
        assertSame([['path' => 'tests/ChainTest.php', 'hop' => 3]], $two['tests']['items']);
    }

    /**
     * A test past the last ring reaches only the rings the component it uses
     * depends on. `App\Checkout` < `Tests\Helper` (a test, one hop) < E2 <
     * ... < E6 < `Tests\FarTest` (eight hops), and `App\Direct` one hop out
     * on its own: the far test reaches rings two and three through the
     * helper's chain, never `App\Direct` in ring one.
     */
    #[Group('query')]
    public function testATestPastTheLastRingCountsOnlyForTheRingsItReaches(): void
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
        $calls($node('App\\Direct', 'src/Direct.php'), $ids['checkout'], 'src/Direct.php:5');
        $previous = $node('Tests\\Helper', 'tests/Helper.php', test: true);
        $calls($previous, $ids['checkout'], 'tests/Helper.php:5');
        foreach ([2, 3, 4, 5, 6] as $k) {
            $current = $node("App\\E{$k}", "src/E{$k}.php");
            $calls($current, $previous, "src/E{$k}.php:5");
            $previous = $current;
        }
        $mid = $node('App\\E7', 'src/E7.php');
        $calls($mid, $previous, 'src/E7.php:5');
        $calls($node('Tests\\FarTest', 'tests/FarTest.php', test: true), $mid, 'tests/FarTest.php:5');
        $repository->completeScan($ids['project'], $ids['scan']);

        $rings = (new BlastRadiusService($pdo))->rings('/workspace/fixture-shop', 'App\\Checkout');

        [$one, $two, $far] = $rings['rings'];
        assertSame(['App\\Direct'], array_column($one['items'], 'canonical_name'));
        assertSame([0, []], [$one['tested'], $one['tests']['items']]);
        assertSame([['path' => 'tests/FarTest.php', 'hop' => 8]], $two['tests']['items']);
        assertSame([['path' => 'tests/FarTest.php', 'hop' => 8]], $far['tests']['items']);
    }

    /**
     * When the tests' own searches run out of time, a member they never
     * reached is not known to be untested: the rings say the tests were
     * searched only in part. The chain of the first case, with no time for
     * those searches; given time, they are searched in full.
     */
    #[Group('query')]
    public function testRingsSayTheTestsWereSearchedInPartWhenTheirSearchRanOutOfTime(): void
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
        foreach ([1, 2, 3, 4, 5, 6] as $k) {
            $current = $node("App\\C{$k}", "src/C{$k}.php");
            $calls($current, $previous, "src/C{$k}.php:5");
            $previous = $current;
        }
        $far = $node('App\\D1', 'src/D1.php');
        $calls($far, $previous, 'src/D1.php:5');
        $calls($node('Tests\\ChainTest', 'tests/ChainTest.php', test: true), $far, 'tests/ChainTest.php:5');
        $repository->completeScan($ids['project'], $ids['scan']);

        $hurried = (new BlastRadiusService($pdo, testSearchMs: 0))->rings('/workspace/fixture-shop', 'App\\Checkout');
        $patient = (new BlastRadiusService($pdo))->rings('/workspace/fixture-shop', 'App\\Checkout');

        assertSame([false, true], [$hurried['truncated'], $hurried['tests_partial']]);
        assertSame([0, 0, 0], array_column($hurried['rings'], 'tested'));
        assertSame([false, [1, 1, 4]], [$patient['tests_partial'], array_column($patient['rings'], 'tested')]);
    }
}
