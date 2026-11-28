<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity;

use Knossos\Scan\ProjectScanService;
use Knossos\TestFidelity\TestRuleCatalog;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;

use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * The Python residuals the gap sweep settled, through a real scan of
 * `python/residuals`.
 *
 * G4-03: a name another file writes on a module or class
 * (`config.DEBUG = True` and `Settings.verbose = True` in `startup.py`) opens
 * that target's list, so a double of it is no ghost, while a module nothing
 * writes keeps its ghost. A rescan of only the writing file judges again.
 */
#[Group('test-fidelity')]
#[Group('python-scanner')]
final class PythonResidualsTest extends KnossosTestCase
{
    private const FIXTURE = '/tests/Fixtures/test-fidelity/python/residuals';

    public function testANameAnotherFileWritesIsNoGhostAndAModuleNothingWritesKeepsOne(): void
    {
        [$pdo] = $this->scanned();

        assertSame(['GHOST_MEMBER/missing_export/definite@tests/test_cross_file.py:25'], self::findingsIn($pdo, 'tests/test_cross_file.py'));
    }

    public function testARescanOfOnlyTheWritingFileJudgesAgain(): void
    {
        [$pdo, $service, $root] = $this->scanned();
        $writer = $root . '/src/startup.py';
        $original = (string) file_get_contents($writer);

        file_put_contents($writer, "def enable() -> None:\n    pass\n");
        $rescanned = $service->scan($root);

        // Only the writer is parsed again: the test file reads neither it nor the conftest that runs it by name.
        assertSame(1, $rescanned->data['parsed_files']);
        assertSame([
            'GHOST_MEMBER/missing_export/definite@tests/test_cross_file.py:15',
            'GHOST_MEMBER/missing_export/definite@tests/test_cross_file.py:25',
            'GHOST_MEMBER/missing_member/definite@tests/test_cross_file.py:20',
        ], self::findingsIn($pdo, 'tests/test_cross_file.py'));

        file_put_contents($writer, $original);
        $service->scan($root);

        assertSame(['GHOST_MEMBER/missing_export/definite@tests/test_cross_file.py:25'], self::findingsIn($pdo, 'tests/test_cross_file.py'));
    }

    /**
     * The writer reads the target's file: when `config.py` gains the class
     * `startup.py` writes, a rescan parses the writer again, so its write
     * opens the new class and `:20` stays silent (Fix round 1).
     */
    public function testATargetGainingTheClassTheWriterWritesParsesTheWriterAgain(): void
    {
        [$pdo, $service, $root] = $this->scanned();
        $target = $root . '/src/config.py';
        $original = (string) file_get_contents($target);
        file_put_contents($target, "FLAG = 0\n\n\ndef flag() -> int:\n    return FLAG\n");
        $service->scan($root);

        file_put_contents($target, $original);
        $rescanned = $service->scan($root);

        // The target, its writer and the test that reads it are parsed again.
        assertSame(3, $rescanned->data['parsed_files']);
        assertSame(['GHOST_MEMBER/missing_export/definite@tests/test_cross_file.py:25'], self::findingsIn($pdo, 'tests/test_cross_file.py'));
    }

    public function testAMatcherListingTheReceiverOfACallThroughTheClassIsNoMismatch(): void
    {
        [$pdo] = $this->scanned();

        // `Repo.save(repo, item)` in production: the receiver is an argument; one more is not, nor is it for `Ledger().add(item)`.
        assertSame([
            'ARITY_MISMATCH/call_arity/definite@tests/test_unbound.py:18',
            'ARITY_MISMATCH/call_arity/definite@tests/test_unbound.py:25',
        ], self::findingsIn($pdo, 'tests/test_unbound.py'));
    }

    /**
     * U1 (the user's decision of 2026-11-27): each write `docs/languages/python.md`
     * lists as not followed (G4-02, G4-05, G4-08, G4-12) gives a warning, never a
     * definite; a plain class beside them keeps its definite ghost and arity.
     */
    public function testAFindingThatRestsOnAWriteNotFollowedIsAWarning(): void
    {
        [$pdo] = $this->scanned();

        // A nested class's double names `Outer.Inner`, which the worker lists as `unfollowed.Inner`: it gives nothing.
        assertSame([
            'ARITY_MISMATCH/call_arity/definite@tests/test_unfollowed.py:71',
            'ARITY_MISMATCH/call_arity/warning@tests/test_unfollowed.py:54 external_base',
            'GHOST_MEMBER/missing_export/warning@tests/test_unfollowed.py:24 module_self_write',
            'GHOST_MEMBER/missing_member/definite@tests/test_unfollowed.py:66',
            'GHOST_MEMBER/missing_member/warning@tests/test_unfollowed.py:14 self_alias',
            'GHOST_MEMBER/missing_member/warning@tests/test_unfollowed.py:19 instance_write',
            'GHOST_MEMBER/missing_member/warning@tests/test_unfollowed.py:34 default_holder',
            'GHOST_MEMBER/missing_member/warning@tests/test_unfollowed.py:39 rebound_builtin',
            'GHOST_MEMBER/missing_member/warning@tests/test_unfollowed.py:44 builtins_exec',
            'GHOST_MEMBER/missing_member/warning@tests/test_unfollowed.py:49 assigned_frame_hack',
            'TAUTOLOGY/constant/warning@tests/test_unfollowed.py:62 frame_write',
        ], self::findingsIn($pdo, 'tests/test_unfollowed.py'));
    }

    /**
     * `list_test_rules` publishes the confidences each rule reports at: every
     * confidence the U1 residuals give (a ghost, an arity and a constant that
     * rest on a write not followed are warnings) is in its rule's entry.
     */
    public function testTheCatalogListsEveryConfidenceTheResidualsReport(): void
    {
        [$pdo] = $this->scanned();
        $listed = array_column(TestRuleCatalog::all(), 'confidences', 'category');
        $reported = [];
        foreach ($pdo->query('SELECT DISTINCT category, confidence FROM test_findings')->fetchAll(PDO::FETCH_NUM) as [$category, $confidence]) {
            $reported[$category][] = $confidence;
        }

        foreach (['GHOST_MEMBER', 'ARITY_MISMATCH', 'TAUTOLOGY'] as $category) {
            assertContains('warning', $reported[$category] ?? [], $category);
        }
        foreach ($reported as $category => $confidences) {
            foreach ($confidences as $confidence) {
                assertContains($confidence, $listed[$category], $category);
            }
        }
    }

    /**
     * A fresh scan of a copy of the fixture.
     *
     * @return array{PDO, ProjectScanService, string}
     */
    private function scanned(): array
    {
        $root = self::scratchPath('python-residuals');
        $this->copyTree(self::repositoryRoot() . self::FIXTURE, $root);
        $pdo = $this->freshTestDatabase();
        $service = new ProjectScanService($pdo, self::repositoryRoot(), [$root]);
        $service->scan($root);

        return [$pdo, $service, $root];
    }

    /**
     * The active scan's findings in one file as `category/sub_rule/confidence@file:line`, with the residual a warning rests on (U1), sorted.
     *
     * @return list<string>
     */
    private static function findingsIn(PDO $pdo, string $file): array
    {
        $statement = $pdo->prepare(
            'SELECT f.category, f.sub_rule, f.confidence, fl.relative_path, f.start_line, f.attributes_json FROM test_findings f '
            . 'JOIN projects p ON p.id = f.project_id AND f.last_scan_id = p.active_scan_id JOIN files fl ON fl.id = f.file_id WHERE fl.relative_path = ?',
        );
        $statement->execute([$file]);
        $findings = array_map(static function (array $row): string {
            $residual = json_decode((string) array_pop($row), true)['residual'] ?? null;

            return vsprintf('%s/%s/%s@%s:%d', $row) . (is_string($residual) ? ' ' . $residual : '');
        }, $statement->fetchAll(PDO::FETCH_NUM));
        sort($findings);

        return $findings;
    }
}
