<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity;

use Knossos\TestFidelity\TestFindingSuppressions;
use Knossos\TestFidelity\TurnTestFindings;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;

use function PHPUnit\Framework\assertCount;
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

    /** R13: the watcher took src.ts in, made the ghost, then took spec.ts in, whose baseline already holds it. */
    public function testAKeyIsBeforeOnlyWhenEveryTakenInFileItTouchesHeldIt(): void
    {
        $held = ['src.ts' => ['ghost'], 'spec.ts' => ['ghost', 'own']];
        $later = ['before' => ['src.ts' => 'H0', 'spec.ts' => 'H1'], 'test_baselines' => ['src.ts' => self::NONE, 'spec.ts' => ['keys' => ['ghost', 'own'], 'truncated' => false]]];
        $old = ['before' => ['src.ts' => 'H0', 'spec.ts' => 'H1'], 'test_baselines' => ['src.ts' => ['keys' => ['ghost'], 'truncated' => false], 'spec.ts' => ['keys' => ['ghost', 'own'], 'truncated' => false]]];

        assertSame(['own'], TurnTestFindings::before(['src.ts', 'spec.ts'], $held, $later));
        assertSame(['ghost', 'own'], TurnTestFindings::before(['src.ts', 'spec.ts'], $held, $old));
    }

    /**
     * A definite missing_module stored with the raw specifier `../src/UserService.js`, from `tests/UserService.spec.ts`:
     * it resolved to no target, and can name the file `src/UserService.ts` a turn deletes.
     */
    private function storedMissingModule(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE files (id INTEGER PRIMARY KEY, relative_path TEXT)');
        $pdo->exec('CREATE TABLE nodes (id INTEGER PRIMARY KEY, display_name TEXT, canonical_name TEXT, file_id INTEGER)');
        $pdo->exec('CREATE TABLE test_findings (id INTEGER PRIMARY KEY, project_id TEXT, node_id INTEGER, target_node_id INTEGER, file_id INTEGER, attributes_json TEXT, category TEXT, confidence TEXT, rule_id TEXT, sub_rule TEXT, message TEXT, start_line INTEGER)');
        $pdo->exec("INSERT INTO files (id, relative_path) VALUES (1, 'tests/UserService.spec.ts')");
        $pdo->exec("INSERT INTO nodes (id, display_name, canonical_name, file_id) VALUES (1, 'vi.mock', 'tests/UserService.spec.ts#double:3:1', 1)");
        $pdo->prepare('INSERT INTO test_findings (id, project_id, node_id, target_node_id, file_id, attributes_json, category, confidence, rule_id, sub_rule, message, start_line) VALUES (1, ?, 1, NULL, 1, ?, ?, ?, ?, ?, ?, 3)')->execute([
            'p', json_encode(['specifier' => '../src/UserService.js']), 'GHOST_MEMBER', 'definite', 'test.fidelity.ghost_member.v1', 'missing_module', 'vi.mock names a module that is not there',
        ]);

        return $pdo;
    }

    /**
     * What the turn brief reports as introduced when `src/UserService.ts` is deleted.
     *
     * @param array{before: array<string, string|null>, test_baselines: array<string, array{keys: list<string>, truncated: bool}|null>} $absorbed
     */
    private function introducedByDeleting(PDO $pdo, array $absorbed): int
    {
        $suppressions = new TestFindingSuppressions([]);
        $held = TurnTestFindings::held($pdo, 'p', $suppressions);
        $before = TurnTestFindings::before(['src/UserService.ts'], $held, $absorbed);
        $section = TurnTestFindings::section($before, TurnTestFindings::affected($pdo, 'p', [], $suppressions, ['src/UserService.ts']), null);
        assertSame('ok', $section['status']);

        return $section['total'];
    }

    /** A mock whose `.js` specifier names the deleted `.ts`, and that was already missing before the turn, is not the deletion's doing. */
    public function testAMissingModuleThatAlreadyNamedTheDeletedFileIsNotIntroducedByDeletingIt(): void
    {
        $pdo = $this->storedMissingModule();
        $keys = TurnTestFindings::held($pdo, 'p', new TestFindingSuppressions([]))['src/UserService.ts'] ?? [];
        assertCount(1, $keys);

        // No scan took the file in: what it holds now was held before the turn.
        assertSame(0, $this->introducedByDeleting($pdo, ['before' => [], 'test_baselines' => []]));
        // A scan did, and its baseline already held the finding.
        assertSame(0, $this->introducedByDeleting($pdo, ['before' => ['src/UserService.ts' => 'H0'], 'test_baselines' => ['src/UserService.ts' => ['keys' => $keys, 'truncated' => false]]]));
    }

    /** The twin: the finding is not in the deleted file's baseline, so the turn made it. */
    public function testAMissingModuleAbsentFromTheBaselineIsIntroducedByDeletingTheFileItNames(): void
    {
        $pdo = $this->storedMissingModule();

        assertSame(1, $this->introducedByDeleting($pdo, ['before' => ['src/UserService.ts' => 'H0'], 'test_baselines' => ['src/UserService.ts' => self::NONE]]));
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
