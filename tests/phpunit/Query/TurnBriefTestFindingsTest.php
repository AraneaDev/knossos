<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\LedgeredScanner;
use Knossos\Query\TurnBriefService;
use Knossos\Roots\AllowedRoots;
use Knossos\Scan\ProjectScanService;
use Knossos\Scan\ScanLedger;
use Knossos\Scan\ScanOptions;
use Knossos\TestFidelity\TestFindingSuppressions;
use Knossos\TestFidelity\TurnTestFindings;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertGreaterThan;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * The turn brief's `test_findings`: the definite test findings the turn
 * introduced, found by following the double back from the production file the
 * turn edited, and compared by key, never by line.
 */
#[Group('test-fidelity')]
#[Group('typescript-scanner')]
final class TurnBriefTestFindingsTest extends KnossosTestCase
{
    private const FIXTURE = 'test-fidelity/ts-audit';
    private const SERVICE = 'src/UserService.ts';
    private const RENAMED = "export class UserService {\n  getProfileV2(id: string): string {\n    return id;\n  }\n}\n";
    private const SPEC = 'tests/ghost.test.ts';
    private const MOCK = 'tests/UserService.spec.ts';

    /** A spec that mocks the whole service module by a raw specifier. */
    private const MOCKS_THE_MODULE = "import { it, vi } from 'vitest';\n\nvi.mock('../src/UserService');\n\nit('runs against the mocked module', () => {});\n";

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

    public function testEditingTheProductionFileIntroducesTheMockItBreaks(): void
    {
        [$pdo, , $root] = $this->project(healthy: true);
        try {
            file_put_contents($root . '/' . self::SERVICE, self::RENAMED);

            $brief = $this->brief($pdo, $root, [self::SERVICE]);

            assertSame('ok', $brief['test_findings']['status']);
            assertSame(1, $brief['test_findings']['total']);
            assertCount(1, $brief['test_findings']['introduced']);
            // The ghost lives in a test file the turn never touched.
            $entry = $brief['test_findings']['introduced'][0];
            assertSame(['key', 'file', 'line', 'message', 'stale'], array_keys($entry));
            assertSame([true, 1], [$entry['stale'], $brief['test_findings']['total_stale']]);
            assertSame('tests/ghost.test.ts', $entry['file']);
            assertSame(true, is_int($entry['line']) && $entry['line'] > 0);
            assertSame(true, $entry['key'] !== '' && $entry['message'] !== '');
            assertSame(false, $brief['test_findings']['truncated']);
            assertSame(null, $brief['test_findings']['suppressions_invalid']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    public function testAnAlreadyStaleMockIsNotIntroduced(): void
    {
        [$pdo, $projectId, $root] = $this->project(healthy: false);
        try {
            file_put_contents($root . '/' . self::SERVICE, (string) file_get_contents($root . '/' . self::SERVICE) . "\n// touched\n");

            $brief = $this->brief($pdo, $root, [self::SERVICE]);

            // The ghost is still reached from the edited file, so the test is not vacuous.
            assertGreaterThan(0, count(TurnTestFindings::affected($pdo, $projectId, [self::SERVICE], new TestFindingSuppressions([]))));
            assertSame('ok', $brief['test_findings']['status']);
            assertSame([], $brief['test_findings']['introduced']);
            assertSame(0, $brief['test_findings']['total']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    public function testMovingAStaleMockDownALineIsNotIntroduced(): void
    {
        [$pdo, $projectId, $root] = $this->project(healthy: false);
        try {
            $ghost = $root . '/tests/ghost.test.ts';
            file_put_contents($ghost, "// a comment pushes every line down\n" . file_get_contents($ghost));

            $brief = $this->brief($pdo, $root, ['tests/ghost.test.ts']);

            assertGreaterThan(0, count(TurnTestFindings::affected($pdo, $projectId, ['tests/ghost.test.ts'], new TestFindingSuppressions([]))));
            assertSame([], $brief['test_findings']['introduced']);
            assertSame(0, $brief['test_findings']['total']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    public function testAFindingInAnEditedTestFileIsIntroduced(): void
    {
        [$pdo, , $root] = $this->project(healthy: true);
        try {
            $ghost = $root . '/tests/ghost.test.ts';
            file_put_contents($ghost, str_replace("'getProfile'", "'getProfil'", (string) file_get_contents($ghost)));

            $brief = $this->brief($pdo, $root, ['tests/ghost.test.ts']);

            assertSame(1, $brief['test_findings']['total']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    public function testWarningsAreNeverIntroduced(): void
    {
        [$pdo, , $root] = $this->project(healthy: true);
        try {
            file_put_contents($root . '/src/Vault.ts', "export class Vault {\n  private secret(): number {\n    return 1;\n  }\n}\n");
            file_put_contents($root . '/tests/vault.test.ts', "import { it, vi } from 'vitest';\nimport { Vault } from '../src/Vault';\n\nit('spies on a private member', () => {\n  vi.spyOn(new Vault() as any, 'secret');\n});\n");

            $brief = $this->brief($pdo, $root, ['src/Vault.ts', 'tests/vault.test.ts']);

            $warnings = $pdo->query("SELECT COUNT(*) FROM test_findings WHERE confidence = 'warning'")->fetchColumn();
            assertGreaterThan(0, (int) $warnings, 'the turn did add a warning');
            assertSame([], $brief['test_findings']['introduced']);
            assertSame(0, $brief['test_findings']['total']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    public function testASuppressedFindingIsNotIntroduced(): void
    {
        [$pdo, , $root] = $this->project(healthy: true);
        try {
            file_put_contents($root . '/knossos.json', json_encode(['version' => 1, 'test_finding_suppressions' => [['rule' => 'GHOST_MEMBER']]]));
            file_put_contents($root . '/' . self::SERVICE, self::RENAMED);

            $brief = $this->brief($pdo, $root, [self::SERVICE]);

            assertSame('ok', $brief['test_findings']['status']);
            assertSame([], $brief['test_findings']['introduced']);
            assertSame(0, $brief['test_findings']['total']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    public function testAWatcherScanThatTookTheEditInStillReportsTheMockItBroke(): void
    {
        [$pdo, $projectId, $root] = $this->project(healthy: true);
        try {
            $since = (string) (new ScanLedger($pdo))->activeSnapshot($projectId);
            file_put_contents($root . '/' . self::SERVICE, self::RENAMED);
            // The watcher records its scan: the graph before the brief already holds the edit, and the ledger what came before it.
            $this->watcherScan($pdo, $root);

            $brief = $this->brief($pdo, $root, [self::SERVICE], $since);

            assertSame([self::SERVICE], $brief['changed_files']);
            assertSame('ok', $brief['test_findings']['status']);
            assertSame(1, $brief['test_findings']['total']);
            assertSame('tests/ghost.test.ts', $brief['test_findings']['introduced'][0]['file']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    public function testAWatcherScanThatTookTheEditInDoesNotReportAnAlreadyStaleMock(): void
    {
        [$pdo, $projectId, $root] = $this->project(healthy: false);
        try {
            $since = (string) (new ScanLedger($pdo))->activeSnapshot($projectId);
            file_put_contents($root . '/' . self::SERVICE, (string) file_get_contents($root . '/' . self::SERVICE) . "\n// touched\n");
            $this->watcherScan($pdo, $root);

            $brief = $this->brief($pdo, $root, [self::SERVICE], $since);

            assertGreaterThan(0, count(TurnTestFindings::affected($pdo, $projectId, [self::SERVICE], new TestFindingSuppressions([]))));
            assertSame('ok', $brief['test_findings']['status']);
            assertSame([], $brief['test_findings']['introduced']);
            assertSame(0, $brief['test_findings']['total']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A ledger entry whose test baseline for the edited file was cut, or that read none (a writer from before them). */
    public function testACutOrMissingTestBaselineLeavesTestFindingsNotEvaluated(): void
    {
        foreach (['cut' => ['keys' => [], 'truncated' => true], 'missing' => null] as $case => $baseline) {
            [$pdo, $projectId, $root] = $this->project(healthy: true);
            try {
                $since = (string) (new ScanLedger($pdo))->activeSnapshot($projectId);
                file_put_contents($root . '/' . self::SERVICE, self::RENAMED);
                $this->watcherScan($pdo, $root);
                $changes = json_decode((string) $pdo->query('SELECT changes_json FROM scan_ledger')->fetchColumn(), true);
                $changes['test_baselines'] = $baseline === null ? null : [self::SERVICE => $baseline];
                $pdo->prepare('UPDATE scan_ledger SET changes_json = ?')->execute([json_encode($changes)]);

                $brief = $this->brief($pdo, $root, [self::SERVICE], $since);

                assertSame('not_evaluated', $brief['test_findings']['status'], $case);
                assertSame([], $brief['test_findings']['introduced'], $case);
            } finally {
                $this->removeTempTree($root);
            }
        }
    }

    public function testALedgerGapLeavesTestFindingsNotEvaluated(): void
    {
        [$pdo, $projectId, $root] = $this->project(healthy: true);
        try {
            $since = (new ScanLedger($pdo))->activeSnapshot($projectId);
            file_put_contents($root . '/' . self::SERVICE, self::RENAMED);
            // A scan the ledger never recorded: the turn's baseline is gone.
            (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root, new ScanOptions(mode: 'incremental'));

            $brief = $this->brief($pdo, $root, [self::SERVICE], $since);

            assertSame('not_evaluated', $brief['test_findings']['status']);
            assertSame([], $brief['test_findings']['introduced']);
            assertSame(0, $brief['test_findings']['total']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    public function testDeletingAMockedModuleIntroducesTheMockItStrands(): void
    {
        [$pdo, , $root] = $this->project(healthy: true, extra: [self::MOCK => self::MOCKS_THE_MODULE]);
        try {
            unlink($root . '/' . self::SERVICE);

            $brief = $this->brief($pdo, $root, [self::SERVICE]);

            assertSame([self::SERVICE], $brief['deleted_files']);
            assertSame(['ok', 1], [$brief['test_findings']['status'], $brief['test_findings']['total']]);
            // The double's target is gone, so only its specifier leads back to the deleted file.
            assertSame(self::MOCK, $brief['test_findings']['introduced'][0]['file']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    public function testRenamingAMockedModuleIntroducesTheMockItStrands(): void
    {
        [$pdo, , $root] = $this->project(healthy: true, extra: [self::MOCK => self::MOCKS_THE_MODULE]);
        try {
            rename($root . '/' . self::SERVICE, $root . '/src/Users.ts');

            $brief = $this->brief($pdo, $root, [self::SERVICE, 'src/Users.ts']);

            assertSame([[self::SERVICE], ['src/Users.ts']], [$brief['deleted_files'], $brief['added_files']]);
            assertSame(['ok', 1], [$brief['test_findings']['status'], $brief['test_findings']['total']]);
            assertSame(self::MOCK, $brief['test_findings']['introduced'][0]['file']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** R14: a model deletes through Bash, which reports nothing; the diff's deletion is the turn's. */
    public function testAnUnreportedDeletionIntroducesTheMockItStrands(): void
    {
        foreach (['fresh' => false, 'watcher' => true] as $case => $watcher) {
            [$pdo, $projectId, $root] = $this->project(healthy: true, extra: [self::MOCK => self::MOCKS_THE_MODULE]);
            try {
                $since = (string) (new ScanLedger($pdo))->activeSnapshot($projectId);
                unlink($root . '/' . self::SERVICE);
                if ($watcher) {
                    $this->watcherScan($pdo, $root);
                }

                $brief = $this->brief($pdo, $root, [], $watcher ? $since : null);

                assertSame([self::SERVICE], $brief['deleted_files'], $case);
                assertSame(['ok', 1], [$brief['test_findings']['status'], $brief['test_findings']['total']], $case);
                assertSame(self::MOCK, $brief['test_findings']['introduced'][0]['file'], $case);
            } finally {
                $this->removeTempTree($root);
            }
        }
    }

    /** `git mv src/UserService.ts src/Users.ts`, with no edit tool run. */
    public function testAnUnreportedRenameIntroducesTheMockItStrands(): void
    {
        [$pdo, , $root] = $this->project(healthy: true, extra: [self::MOCK => self::MOCKS_THE_MODULE]);
        try {
            rename($root . '/' . self::SERVICE, $root . '/src/Users.ts');

            $brief = $this->brief($pdo, $root, []);

            assertSame([[self::SERVICE], ['src/Users.ts']], [$brief['deleted_files'], $brief['added_files']]);
            assertSame(['ok', 1], [$brief['test_findings']['status'], $brief['test_findings']['total']]);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** Past ten deletions the diff is checkout-sized: none of them is the turn's, and the turn is still judged. */
    public function testElevenUnreportedDeletionsIntroduceNothingFromThem(): void
    {
        $others = [];
        for ($n = 1; $n <= TurnTestFindings::MAX_STRANDING_DELETIONS; ++$n) {
            $others['src/Other' . $n . '.ts'] = "export const other{$n} = {$n};\n";
        }
        [$pdo, , $root] = $this->project(healthy: true, extra: [self::MOCK => self::MOCKS_THE_MODULE] + $others);
        try {
            foreach ([self::SERVICE, ...array_keys($others)] as $file) {
                unlink($root . '/' . $file);
            }

            $brief = $this->brief($pdo, $root, []);

            assertCount(TurnTestFindings::MAX_STRANDING_DELETIONS + 1, $brief['deleted_files']);
            assertSame(['ok', 0, []], [$brief['test_findings']['status'], $brief['test_findings']['total'], $brief['test_findings']['introduced']]);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A mock of a module that never existed stays out of the brief when another file goes; the overlap with the deleted path is pinned in TurnTestFindingsTest. */
    public function testAMissingModuleFromBeforeTheTurnIsNotIntroducedByDeletingAnotherFile(): void
    {
        foreach (['fresh' => false, 'watcher' => true] as $case => $watcher) {
            // src/Legacy.* never existed, so the mock names a module that is genuinely missing, and the deletion is of another file.
            [$pdo, $projectId, $root] = $this->project(healthy: true, extra: [self::MOCK => "import { it, vi } from 'vitest';\n\nvi.mock('../src/Legacy.js', () => ({}));\n\nit('runs', () => {});\n"]);
            try {
                $missing = $pdo->query("SELECT COUNT(*) FROM test_findings WHERE sub_rule = 'missing_module'")->fetchColumn();
                assertSame(1, (int) $missing, 'the missing module was there before the turn');
                $since = (string) (new ScanLedger($pdo))->activeSnapshot($projectId);
                unlink($root . '/' . self::SERVICE);
                if ($watcher) {
                    $this->watcherScan($pdo, $root);
                }

                $brief = $this->brief($pdo, $root, [], $watcher ? $since : null);

                assertSame([self::SERVICE], $brief['deleted_files'], $case);
                assertSame(['ok', 0, []], [$brief['test_findings']['status'], $brief['test_findings']['total'], $brief['test_findings']['introduced']], $case);
            } finally {
                $this->removeTempTree($root);
            }
        }
    }

    public function testDeletingAFileNoDoubleNamesIntroducesNothing(): void
    {
        // The spec already mocks a module that is not there: a finding with no target, which no deletion strands.
        [$pdo, , $root] = $this->project(healthy: true, extra: ['src/Unused.ts' => "export const unused = 1;\n", self::MOCK => "import { it, vi } from 'vitest';\n\nvi.mock('../src/Gone');\n\nit('runs', () => {});\n"]);
        try {
            unlink($root . '/src/Unused.ts');

            $brief = $this->brief($pdo, $root, ['src/Unused.ts']);

            assertSame(['src/Unused.ts'], $brief['deleted_files']);
            assertSame(['ok', 0, []], [$brief['test_findings']['status'], $brief['test_findings']['total'], $brief['test_findings']['introduced']]);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** R13: the watcher took the production edit in one scan and the spec's save in a later one, whose baseline already held the ghost. */
    public function testTwoWatcherScansThatTookTheProductionEditThenTheSpecInStillReportTheGhost(): void
    {
        [$pdo, $projectId, $root] = $this->project(healthy: true);
        try {
            $since = (string) (new ScanLedger($pdo))->activeSnapshot($projectId);
            file_put_contents($root . '/' . self::SERVICE, self::RENAMED);
            $this->watcherScan($pdo, $root);
            $this->touch($root, self::SPEC);
            $this->watcherScan($pdo, $root);

            $brief = $this->brief($pdo, $root, [self::SERVICE, self::SPEC], $since);

            assertSame(['ok', 1], [$brief['test_findings']['status'], $brief['test_findings']['total']]);
            assertSame(self::SPEC, $brief['test_findings']['introduced'][0]['file']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    public function testTwoWatcherScansDoNotReportAGhostFromBeforeTheTurn(): void
    {
        [$pdo, $projectId, $root] = $this->project(healthy: false);
        try {
            $since = (string) (new ScanLedger($pdo))->activeSnapshot($projectId);
            $this->touch($root, self::SERVICE);
            $this->watcherScan($pdo, $root);
            $this->touch($root, self::SPEC);
            $this->watcherScan($pdo, $root);

            $brief = $this->brief($pdo, $root, [self::SERVICE, self::SPEC], $since);

            assertGreaterThan(0, count(TurnTestFindings::affected($pdo, $projectId, [self::SERVICE, self::SPEC], new TestFindingSuppressions([]))));
            assertSame(['ok', 0, []], [$brief['test_findings']['status'], $brief['test_findings']['total'], $brief['test_findings']['introduced']]);
        } finally {
            $this->removeTempTree($root);
        }
    }

    public function testTwoWatcherScansThatTookADeletionThenTheSpecInStillReportTheStrandedMock(): void
    {
        [$pdo, $projectId, $root] = $this->project(healthy: true, extra: [self::MOCK => self::MOCKS_THE_MODULE]);
        try {
            $since = (string) (new ScanLedger($pdo))->activeSnapshot($projectId);
            unlink($root . '/' . self::SERVICE);
            $this->watcherScan($pdo, $root);
            $this->touch($root, self::MOCK);
            $this->watcherScan($pdo, $root);

            $brief = $this->brief($pdo, $root, [self::SERVICE, self::MOCK], $since);

            assertSame([self::SERVICE], $brief['deleted_files']);
            assertSame(['ok', 1], [$brief['test_findings']['status'], $brief['test_findings']['total']]);
            assertSame(self::MOCK, $brief['test_findings']['introduced'][0]['file']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A database upgraded from before the test rules: its active scan has no findings because they never ran. */
    public function testABaselineFromAScanTheRulesNeverJudgedLeavesTestFindingsNotEvaluated(): void
    {
        foreach (['watcher' => true, 'fresh' => false] as $case => $watcher) {
            [$pdo, $projectId, $root] = $this->project(healthy: true);
            try {
                $pdo->exec('UPDATE scans SET test_findings_judged = 0');
                $since = (string) (new ScanLedger($pdo))->activeSnapshot($projectId);
                file_put_contents($root . '/' . self::SERVICE, self::RENAMED);
                if ($watcher) {
                    $this->watcherScan($pdo, $root);
                }

                $brief = $this->brief($pdo, $root, [self::SERVICE], $watcher ? $since : null);

                assertSame(['not_evaluated', []], [$brief['test_findings']['status'], $brief['test_findings']['introduced']], $case);
            } finally {
                $this->removeTempTree($root);
            }
        }
    }

    /** An edit that changes the file's bytes and nothing it declares. */
    private function touch(string $root, string $file): void
    {
        file_put_contents($root . '/' . $file, (string) file_get_contents($root . '/' . $file) . "\n// touched\n");
    }

    /** A scan as the live watcher makes one: ledgered, with no policies to read. */
    private function watcherScan(PDO $pdo, string $root): void
    {
        LedgeredScanner::local($pdo, self::repositoryRoot(), AllowedRoots::of([(string) realpath($root)]), [])->scan($root, new ScanOptions(mode: 'incremental'));
    }

    /**
     * @param list<string> $reported
     * @return array<string, mixed>
     */
    private function brief(PDO $pdo, string $root, array $reported, ?string $since = null): array
    {
        return (new TurnBriefService($pdo, ':memory:', self::repositoryRoot()))->brief($root, $reported, [], true, $since);
    }

    /**
     * @param array<string, string> $extra files added to the copy before it is scanned
     * @return array{0: PDO, 1: string, 2: string} a scanned copy of the fixture, with its stale mock fixed when `$healthy`
     */
    private function project(bool $healthy, array $extra = []): array
    {
        $root = self::scratchPath('knossos-stale');
        $this->copyTree(self::repositoryRoot() . '/tests/Fixtures/' . self::FIXTURE, $root);
        unlink($root . '/tests/planted.test.ts');
        if ($healthy) {
            $ghost = $root . '/tests/ghost.test.ts';
            file_put_contents($ghost, str_replace("'getProfil'", "'getProfile'", (string) file_get_contents($ghost)));
        }
        foreach ($extra as $file => $source) {
            file_put_contents($root . '/' . $file, $source);
        }
        $pdo = $this->freshTestDatabase();
        $scan = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);

        return [$pdo, $scan->projectId, $root];
    }
}
