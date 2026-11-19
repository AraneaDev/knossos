<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\TurnBriefService;
use Knossos\Scan\ProjectScanService;
use Knossos\Scan\ScanLedger;
use Knossos\Scan\ScanOptions;
use Knossos\TestFidelity\TurnTestFindings;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertGreaterThan;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;

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
            assertStringContainsString('tests/ghost.test.ts:', $brief['test_findings']['introduced'][0]);
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
            assertGreaterThan(0, count(TurnTestFindings::affected($pdo, $projectId, [self::SERVICE])));
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

            assertGreaterThan(0, count(TurnTestFindings::affected($pdo, $projectId, ['tests/ghost.test.ts'])));
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

    /**
     * @param list<string> $reported
     * @return array<string, mixed>
     */
    private function brief(PDO $pdo, string $root, array $reported, ?string $since = null): array
    {
        return (new TurnBriefService($pdo, ':memory:', self::repositoryRoot()))->brief($root, $reported, [], true, $since);
    }

    /** @return array{0: PDO, 1: string, 2: string} a scanned copy of the fixture, with its stale mock fixed when `$healthy` */
    private function project(bool $healthy): array
    {
        $root = self::scratchPath('knossos-stale');
        $this->copyTree(self::repositoryRoot() . '/tests/Fixtures/' . self::FIXTURE, $root);
        unlink($root . '/tests/planted.test.ts');
        if ($healthy) {
            $ghost = $root . '/tests/ghost.test.ts';
            file_put_contents($ghost, str_replace("'getProfil'", "'getProfile'", (string) file_get_contents($ghost)));
        }
        $pdo = $this->freshTestDatabase();
        $scan = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);

        return [$pdo, $scan->projectId, $root];
    }
}
