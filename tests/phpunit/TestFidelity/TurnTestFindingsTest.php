<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity;

use Knossos\TestFidelity\TurnTestFindings;
use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * The turn brief's `test_findings` section without a scan: how many it lists
 * and in what order, the per-file keys the ledger keeps, and the keys the
 * reported files held when the turn began.
 */
#[Group('test-fidelity')]
final class TurnTestFindingsTest extends KnossosTestCase
{
    private const NONE = ['keys' => [], 'truncated' => false];

    public function testTheSectionListsTenByFileThenLineAndSaysItCutTheRest(): void
    {
        $after = [];
        foreach ([['b.ts', 3], ['a.ts', 9], ['a.ts', 2], ['c.ts', 1], ['b.ts', 1], ['a.ts', 10], ['d.ts', 4], ['d.ts', 3], ['e.ts', 1], ['e.ts', 2], ['f.ts', 1], ['a.ts', 1]] as $n => [$file, $line]) {
            $after[] = ['key' => 'k' . $n, 'category' => 'GHOST_MEMBER', 'message' => 'm' . $n, 'file' => $file, 'line' => $line];
        }

        $section = TurnTestFindings::section(['k3'], $after, null);

        assertSame(TurnTestFindings::MAX_TEST_FINDINGS, 10);
        assertSame([11, true, 'ok'], [$section['total'], $section['truncated'], $section['status']]);
        assertSame(
            ['a.ts:1', 'a.ts:2', 'a.ts:9', 'a.ts:10', 'b.ts:1', 'b.ts:3', 'd.ts:3', 'd.ts:4', 'e.ts:1', 'e.ts:2'],
            array_map(static fn(array $f): string => $f['file'] . ':' . $f['line'], $section['introduced']),
        );
    }

    public function testEachItemSaysWhetherItIsAStaleMockAndTheTotalCountsThemUncapped(): void
    {
        $after = [];
        foreach (['GHOST_MEMBER', 'ARITY_MISMATCH', 'RETURN_DRIFT', 'TAUTOLOGY', 'VISIBILITY_BREACH', 'MOCKS_SUBJECT'] as $n => $category) {
            $after[] = ['key' => 'k' . $n, 'category' => $category, 'message' => 'm', 'file' => 'a.ts', 'line' => $n + 1];
        }
        for ($n = 6; $n < 20; ++$n) {
            $after[] = ['key' => 'k' . $n, 'category' => 'ARITY_MISMATCH', 'message' => 'm', 'file' => 'b.ts', 'line' => $n];
        }

        $section = TurnTestFindings::section([], $after, null);

        assertSame([true, true, true, false, false, false], array_column(array_slice($section['introduced'], 0, 6), 'stale'));
        assertSame([20, 17], [$section['total'], $section['total_stale']]);
        assertSame(['key', 'file', 'line', 'message', 'stale'], array_keys($section['introduced'][0]));
        assertSame(0, TurnTestFindings::notEvaluated()['total_stale']);
    }

    public function testTenIntroducedAreAllListedAndNotTruncated(): void
    {
        $after = array_map(static fn(int $n): array => ['key' => 'k' . $n, 'category' => 'GHOST_MEMBER', 'message' => 'm', 'file' => 'a.ts', 'line' => $n], range(1, 10));

        $section = TurnTestFindings::section([], $after, null);

        assertSame([10, false, 10], [$section['total'], $section['truncated'], count($section['introduced'])]);
    }

    public function testTheLedgerKeepsAtMostTheCapOfKeysPerFileAndSaysWhenItCut(): void
    {
        $many = array_map(static fn(int $n): string => sprintf('k%04d', $n), range(1, TurnTestFindings::MAX_BASELINE_KEYS + 1));

        $baselines = TurnTestFindings::baselines(['big.ts' => $many, 'small.ts' => ['k1']]);

        assertSame(array_slice($many, 0, TurnTestFindings::MAX_BASELINE_KEYS), $baselines['big.ts']['keys']);
        assertSame(true, $baselines['big.ts']['truncated']);
        assertSame(['keys' => ['k1'], 'truncated' => false], $baselines['small.ts']);
    }

    public function testFilesNoScanTookInAreJudgedByWhatTheyHoldNow(): void
    {
        $absorbed = ['before' => [], 'test_baselines' => []];

        assertSame(['k1', 'k2'], TurnTestFindings::before(['a.ts', 'b.ts'], ['a.ts' => ['k1'], 'b.ts' => ['k1', 'k2'], 'c.ts' => ['k3']], $absorbed));
    }

    public function testFilesAScanTookInAreJudgedByWhatTheyHeldBeforeIt(): void
    {
        // The watcher took src.ts in: it now holds the ghost `new`, which it did not hold before.
        $absorbed = ['before' => ['src.ts' => 'H0'], 'test_baselines' => ['src.ts' => ['keys' => ['old'], 'truncated' => false]]];
        $held = ['src.ts' => ['new', 'old'], 'spec.ts' => ['new', 'old', 'own']];

        // spec.ts was not taken in, but its `new` and `old` touch src.ts, so src.ts's baseline judges them.
        assertSame(['old', 'own'], TurnTestFindings::before(['src.ts', 'spec.ts'], $held, $absorbed));
    }

    public function testAnUnknownOrCutBaselineLeavesTheTurnUnjudged(): void
    {
        $held = ['src.ts' => ['k']];

        assertNull(TurnTestFindings::before(['src.ts'], $held, null));
        assertNull(TurnTestFindings::before(['src.ts'], $held, ['before' => ['src.ts' => 'H0'], 'test_baselines' => ['src.ts' => null]]));
        assertNull(TurnTestFindings::before(['src.ts'], $held, ['before' => ['src.ts' => 'H0'], 'test_baselines' => []]));
        assertNull(TurnTestFindings::before(['src.ts'], $held, ['before' => ['src.ts' => 'H0'], 'test_baselines' => ['src.ts' => ['keys' => ['k'], 'truncated' => true]]]));
        assertSame([], TurnTestFindings::before(['src.ts'], $held, ['before' => ['src.ts' => 'H0'], 'test_baselines' => ['src.ts' => self::NONE]]));
    }
}
