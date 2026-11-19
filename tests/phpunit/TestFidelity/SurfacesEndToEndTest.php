<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\DashboardService;
use Knossos\Query\LedgeredScanner;
use Knossos\Query\TurnBriefService;
use Knossos\Roots\AllowedRoots;
use Knossos\Scan\ProjectScanService;
use Knossos\Scan\ScanOptions;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;

use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertGreaterThanOrEqual;
use function PHPUnit\Framework\assertNotContains;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * The phase 2 surfaces together, on a real scan of the Nemesis TypeScript
 * fixture. Renaming `UserService.updateProfile` (which `UserService.spec.ts`
 * stubs at line 29) to `patchProfile` makes that stub a ghost member in a test
 * file the edit never touched; the turn brief, the dashboard, the quality gate
 * and `audit_tests` all have to agree on it, and on what a suppression, a
 * cosmetic move and a baseline from before this phase do to it.
 */
#[Group('test-fidelity')]
#[Group('typescript-scanner')]
final class SurfacesEndToEndTest extends KnossosTestCase
{
    private const FIXTURE = '/tests/Fixtures/test-fidelity/typescript/nemesis';
    private const SERVICE = 'src/UserService.ts';
    private const SPEC = 'tests/UserService.spec.ts';
    private const BUDGET = ['new_definite_test_findings' => 0];

    private string|false $allowedRoots = false;

    private string|false $rootsFile = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allowedRoots = getenv('KNOSSOS_ALLOWED_ROOTS');
        $this->rootsFile = getenv('KNOSSOS_ROOTS_FILE');
        putenv('KNOSSOS_ALLOWED_ROOTS=' . sys_get_temp_dir());
        putenv('KNOSSOS_ROOTS_FILE');
    }

    protected function tearDown(): void
    {
        putenv(is_string($this->allowedRoots) ? 'KNOSSOS_ALLOWED_ROOTS=' . $this->allowedRoots : 'KNOSSOS_ALLOWED_ROOTS');
        putenv(is_string($this->rootsFile) ? 'KNOSSOS_ROOTS_FILE=' . $this->rootsFile : 'KNOSSOS_ROOTS_FILE');
        parent::tearDown();
    }

    public function testEditingOnlyTheProductionFileIntroducesTheGhostInAnUntouchedTestFile(): void
    {
        [$pdo, $root, $baseline] = $this->scannedBaseline();
        $this->renameTheMember($root);

        $brief = $this->brief($pdo, $root, [self::SERVICE]);

        assertSame('ok', $brief['test_findings']['status']);
        assertSame(1, $brief['test_findings']['total']);
        $entry = $brief['test_findings']['introduced'][0];
        assertSame([self::SPEC, 29], [$entry['file'], $entry['line']]);
        assertSame(null, $brief['test_findings']['suppressions_invalid']);
        // The other surfaces say the same of the same scan.
        $section = (new DashboardService($pdo))->dashboard($root)['test_findings'];
        assertContains($entry['key'], $section['by_production_file'][self::SERVICE]['keys']);
        $gate = $this->gate($pdo, $baseline, self::BUDGET);
        assertSame(false, $gate['passed']);
        assertGreaterThanOrEqual(1, $gate['checks'][0]['actual']);
    }

    /**
     * The default `watch: true`: the watcher scans the rename before the turn
     * ends, and the brief is asked from the turn's start and reuses that scan.
     */
    public function testTheGhostIsStillIntroducedWhenTheWatcherTookTheRenameInFirst(): void
    {
        [$pdo, $root, $baseline] = $this->scannedBaseline();
        $this->renameTheMember($root);
        LedgeredScanner::local($pdo, self::repositoryRoot(), AllowedRoots::of([(string) realpath($root)]))->scan($root, new ScanOptions(mode: 'incremental'));

        $brief = (new TurnBriefService($pdo, ':memory:', self::repositoryRoot()))->brief($root, [self::SERVICE], [], true, $baseline, true);

        assertSame(false, $brief['scanned'], 'the brief reused the watcher\'s scan');
        assertSame([self::SERVICE], $brief['changed_files']);
        assertSame(['ok', 1], [$brief['test_findings']['status'], $brief['test_findings']['total']]);
        $entry = $brief['test_findings']['introduced'][0];
        assertSame([self::SPEC, 29], [$entry['file'], $entry['line']]);
    }

    /**
     * A module renamed under a spec that mocks it by path: the watcher takes
     * the rename in, and the stranded `vi.mock` leads back to the old path,
     * which the turn reported and the scan deleted.
     */
    public function testRenamingAMockedModuleIntroducesTheMockItStrandsWhenTheWatcherTookItIn(): void
    {
        $mock = 'tests/UserService.mock.spec.ts';
        [$pdo, $root, $baseline] = $this->scannedBaseline([$mock => "import { it, vi } from 'vitest';\n\nvi.mock('../src/UserService');\n\nit('runs against the mocked module', () => {});\n"]);
        rename($root . '/' . self::SERVICE, $root . '/src/Users.ts');
        LedgeredScanner::local($pdo, self::repositoryRoot(), AllowedRoots::of([(string) realpath($root)]))->scan($root, new ScanOptions(mode: 'incremental'));

        $brief = (new TurnBriefService($pdo, ':memory:', self::repositoryRoot()))->brief($root, [self::SERVICE, 'src/Users.ts'], [], true, $baseline, true);

        assertSame([false, [self::SERVICE]], [$brief['scanned'], $brief['deleted_files']]);
        assertSame(['ok', 1], [$brief['test_findings']['status'], $brief['test_findings']['total']]);
        assertSame([$mock, 3], [$brief['test_findings']['introduced'][0]['file'], $brief['test_findings']['introduced'][0]['line']]);
    }

    public function testABaselineFromBeforeThisPhaseIsIndeterminateNotPassOrFail(): void
    {
        [$pdo, $root, $baseline] = $this->scannedBaseline();
        $this->renameTheMember($root);
        $this->brief($pdo, $root, [self::SERVICE]);
        $pdo->exec('UPDATE scan_snapshots SET test_findings_recorded = 0');
        $pdo->exec('DELETE FROM snapshot_test_findings');

        $gate = $this->gate($pdo, $baseline, self::BUDGET);

        $check = $gate['checks'][0];
        assertSame([true, 'baseline_has_no_test_findings', false], [$check['indeterminate'], $check['indeterminate_reason'], $check['passed']]);
        assertSame(false, $gate['passed']);
    }

    public function testASuppressionAddedWithoutARescanDropsTheFindingEverywhere(): void
    {
        [$pdo, $root, $baseline] = $this->scannedBaseline();
        $this->renameTheMember($root);
        $this->brief($pdo, $root, [self::SERVICE]);
        assertContains('GHOST_MEMBER', array_column($this->audit($pdo)['items'], 'category'));

        file_put_contents($root . '/knossos.json', (string) json_encode(['version' => 1, 'test_finding_suppressions' => [['rule' => 'GHOST_MEMBER', 'path' => 'tests/']]]));

        $section = (new DashboardService($pdo))->dashboard($root)['test_findings'];
        assertSame([], $section['by_production_file']);
        assertNotContains('GHOST_MEMBER', array_column($section['list'], 'category'));
        $gate = $this->gate($pdo, $baseline, self::BUDGET);
        assertSame([0, true], [$gate['checks'][0]['actual'], $gate['passed']]);
        $audit = $this->audit($pdo);
        assertNotContains('GHOST_MEMBER', array_column($audit['items'], 'category'));
        assertSame(null, $audit['suppressions_invalid']);
        // Only the ghosts went: the other findings are still audited.
        assertContains('TAUTOLOGY', array_column($audit['items'], 'category'));
    }

    public function testACosmeticEditThatOnlyMovesAStaleMockDownALineIsNotIntroducedAgain(): void
    {
        [$pdo, $root, $baseline] = $this->scannedBaseline();
        $this->renameTheMember($root);
        $this->brief($pdo, $root, [self::SERVICE]);
        $spec = $root . '/' . self::SPEC;
        file_put_contents($spec, "// a comment pushes every line down\n" . file_get_contents($spec));

        $brief = $this->brief($pdo, $root, [self::SPEC]);

        $ghosts = array_filter($this->audit($pdo)['items'], static fn(array $item): bool => $item['category'] === 'GHOST_MEMBER');
        assertContains(30, array_column($ghosts, 'line'), 'the ghost is still there, one line down');
        assertSame([], $brief['test_findings']['introduced']);
        assertSame(0, $brief['test_findings']['total']);
        // The gate keys by the same stable key: against the baseline, the one ghost the rename added is counted once.
        assertSame(1, $this->gate($pdo, $baseline, self::BUDGET)['checks'][0]['actual']);
    }

    /**
     * @param array<string, string> $extra files added to the copy before it is scanned
     * @return array{0: PDO, 1: string, 2: string} the database, a scanned copy of the fixture, and the baseline snapshot's id
     */
    private function scannedBaseline(array $extra = []): array
    {
        $root = self::scratchPath('surfaces-end-to-end');
        $this->copyTree(self::repositoryRoot() . self::FIXTURE . '/src', $root . '/src');
        $this->copyTree(self::repositoryRoot() . self::FIXTURE . '/tests', $root . '/tests');
        foreach ($extra as $file => $source) {
            file_put_contents($root . '/' . $file, $source);
        }
        $pdo = $this->freshTestDatabase();
        $scan = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);

        return [$pdo, $root, (string) $scan->snapshotId];
    }

    /** `updateProfile` is the member `UserService.spec.ts:29` stubs; renamed, the stub is a ghost. */
    private function renameTheMember(string $root): void
    {
        $production = $root . '/' . self::SERVICE;
        file_put_contents($production, str_replace('updateProfile(', 'patchProfile(', (string) file_get_contents($production)));
    }

    /**
     * @param list<string> $reported
     * @return array<string, mixed>
     */
    private function brief(PDO $pdo, string $root, array $reported): array
    {
        return (new TurnBriefService($pdo, ':memory:', self::repositoryRoot()))->brief($root, $reported, [], true);
    }

    /**
     * @param array<string, int> $budgets
     * @return array<string, mixed>
     */
    private function gate(PDO $pdo, string $baseline, array $budgets): array
    {
        return ArchitectureQueryService::forDatabase($pdo)->services()->gateQueries->qualityGate($this->projectId($pdo), $baseline, $budgets, [], false)->data;
    }

    /** @return array<string, mixed> */
    private function audit(PDO $pdo): array
    {
        return ArchitectureQueryService::forDatabase($pdo)->services()->testFidelityQueries->auditTests($this->projectId($pdo), limit: 200)->data;
    }

    private function projectId(PDO $pdo): string
    {
        return (string) $pdo->query('SELECT id FROM projects')->fetchColumn();
    }
}
