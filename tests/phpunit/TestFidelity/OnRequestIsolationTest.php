<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity;

use Knossos\Git\ProcessGitWorkingTreeProvider;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\DashboardService;
use Knossos\Query\TurnBriefService;
use Knossos\Scan\ProjectScanService;
use Knossos\TestFidelity\ActiveTestFindings;
use Knossos\TestFidelity\TestFindingSuppressions;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * On-request findings are computed when asked and never stored, so no surface
 * that reads stored findings may see one (Review Focus 1): the count the gate
 * and `audit_tests` share, the dashboard, the turn brief, the gate's metric
 * and SARIF, `snapshot_diff` and `architecture_trends`.
 *
 * A planted test file that only `OVER_MOCKING` judges, a change to a
 * doubled class's file that only `STALE_SINCE` judges, and a fixture whose
 * records drifted from their type, which only `FIXTURE_DRIFT` judges, leave
 * each of them as they were. Each plant is proved live first (preflight F16): `audit_tests`
 * lists it on request, so the equality is not one of two empty answers.
 */
#[Group('test-fidelity')]
#[Group('typescript-scanner')]
final class OnRequestIsolationTest extends KnossosTestCase
{
    private const FIXTURE = '/tests/Fixtures/test-fidelity/typescript/nemesis';
    private const PLANT = 'tests/overMocked.spec.ts';

    private const CONTROL = 'src/Notes.ts';

    /** A fixture of `UserProfile` records that lost its `email`: only `FIXTURE_DRIFT` judges it. */
    private const FIXTURE_PLANT = 'tests/fixtures/userProfiles.fixture.json';

    /** The class `tests/UserService.spec.ts` doubles: changed and uncommitted, it makes that spec stale. */
    private const TARGET = 'src/UserService.ts';

    /** One double of the one module the file imports, invoked by production code, and no assertion that reads production. */
    private const PLANTED = <<<'TS'
        import { expect, it, vi } from 'vitest';
        import { UserService } from '../src/UserService.js';

        it('purges without touching a profile', () => {
          const service = new UserService();
          const update = vi.spyOn(service, 'updateProfile').mockReturnValue(true);
          service.purgeAll();
          expect(update).not.toHaveBeenCalled();
        });

        TS;

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

    public function testOnRequestFindingsReachNoSurface(): void
    {
        $root = self::scratchPath('knossos-stale-on-request-isolation');
        try {
            $this->copyTree(self::repositoryRoot() . self::FIXTURE . '/src', $root . '/src');
            $this->copyTree(self::repositoryRoot() . self::FIXTURE . '/tests', $root . '/tests');
            foreach ([['init', '--quiet'], ['add', '-A'], ['commit', '--quiet', '-m', 'base']] as $args) {
                $this->runFixtureCommand(['git', '-C', $root, '-c', 'user.name=Knossos Test', '-c', 'user.email=test@example.test', '-c', 'commit.gpgsign=false', ...$args]);
            }
            $pdo = $this->freshTestDatabase();
            $baseline = (string) (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root)->snapshotId;
            // Without the plant: a turn that adds a production file, so the gate and the diff have a later snapshot to read.
            file_put_contents($root . '/' . self::CONTROL, "export const NOTE = 1;\n");
            $without = $this->surfaces($pdo, $root, $baseline, [self::CONTROL]);
            self::assertSame([], $this->onRequestIn($pdo, self::PLANT));
            self::assertSame([], $this->onRequestIn($pdo, self::FIXTURE_PLANT));
            self::assertSame([], $this->staleIn($pdo));

            file_put_contents($root . '/' . self::PLANT, self::PLANTED);
            mkdir($root . '/tests/fixtures');
            file_put_contents($root . '/' . self::FIXTURE_PLANT, '[{"id": "u-1", "displayName": "Ada"}]');
            file_put_contents($root . '/' . self::TARGET, (string) file_get_contents($root . '/' . self::TARGET) . "// reworked\n");
            $with = $this->surfaces($pdo, $root, $baseline, [self::PLANT, self::TARGET, self::FIXTURE_PLANT]);

            // The plant is live: on request, it is over-mocked.
            self::assertSame([[self::PLANT, 1, 'OVER_MOCKING', 'warning']], array_map(
                static fn(array $item): array => [$item['file'], $item['line'], $item['category'], $item['confidence']],
                $this->onRequestIn($pdo, self::PLANT),
            ));
            // The fixture drifted from the type it holds records of.
            self::assertSame([[self::FIXTURE_PLANT, 1, 'FIXTURE_DRIFT', 'warning']], array_map(
                static fn(array $item): array => [$item['file'], $item['line'], $item['category'], $item['confidence']],
                $this->onRequestIn($pdo, self::FIXTURE_PLANT),
            ));
            // And in scope git-diff, the spec that doubles the changed class is stale.
            self::assertNotSame([], $this->staleIn($pdo));
            self::assertSame(['tests/UserService.spec.ts'], array_values(array_unique(array_column($this->staleIn($pdo), 'file'))));
            // Each turn took its file in: the brief rescanned it.
            foreach ([self::CONTROL, self::PLANT, self::TARGET] as $file) {
                self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM files WHERE relative_path = '" . $file . "'")->fetchColumn(), $file);
            }
            // And no surface saw it.
            self::assertGreaterThanOrEqual(1, $without['count'], 'the fixture has stored findings to compare');
            self::assertCount(2, $without['trends']);
            self::assertGreaterThanOrEqual(1, $without['trends'][1][0], 'the trend has definite findings to compare');
            foreach (array_keys($without) as $surface) {
                self::assertSame($without[$surface], $with[$surface], $surface);
            }
            self::assertSame([0, 0], array_values($with['snapshot_diff']['counts']));
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * Each surface's test-finding part, after a turn that reported `$files`.
     *
     * @param list<string> $files
     * @return array<string, mixed>
     */
    private function surfaces(PDO $pdo, string $root, string $baseline, array $files): array
    {
        $brief = (new TurnBriefService($pdo, ':memory:', self::repositoryRoot()))->brief($root, $files, [], true);
        $projectId = (string) $pdo->query('SELECT id FROM projects')->fetchColumn();
        $services = ArchitectureQueryService::forDatabase($pdo)->services();
        $gate = $services->gateQueries->qualityGate($projectId, $baseline, ['new_definite_test_findings' => 0], [], true)->data;
        $sarif = array_values(array_filter(
            $gate['sarif']['runs'][0]['results'],
            static fn(array $result): bool => str_starts_with((string) $result['ruleId'], 'test.fidelity.'),
        ));

        return [
            'count' => (new ActiveTestFindings($pdo, $projectId, new TestFindingSuppressions([])))->count(),
            'dashboard' => (new DashboardService($pdo))->dashboard($root)['test_findings'],
            'brief' => $brief['test_findings'],
            'gate_metric' => $gate['metrics']['new_definite_test_findings'],
            'gate_checks' => $gate['checks'],
            'sarif' => $sarif,
            'snapshot_diff' => $services->diffQueries->snapshotDiff($projectId, $baseline)->data['test_findings'],
            // G6-08b: the baseline's and the active snapshot's test-finding counts; each turn adds a point between them.
            'trends' => array_map(
                static fn(array $point): array => [$point['definite_test_findings'], $point['stale_test_findings']],
                array_values(array_filter(
                    $services->gateQueries->architectureTrends($projectId, 20)->data['series'],
                    static fn(array $point): bool => $point['scan_id'] === $baseline || ($point['active'] ?? false) === true,
                )),
            ),
            'audit_scan' => $services->testFidelityQueries->auditTests($projectId, limit: 200)->data['items'],
        ];
    }

    /**
     * The on-request items `audit_tests` lists in one file.
     *
     * @return list<array<string, mixed>>
     */
    private function onRequestIn(PDO $pdo, string $file): array
    {
        $projectId = (string) $pdo->query('SELECT id FROM projects')->fetchColumn();

        return ArchitectureQueryService::forDatabase($pdo)->services()->testFidelityQueries->auditTests($projectId, $file, tier: 'on_request')->data['items'];
    }

    /**
     * The `STALE_SINCE` items `audit_tests` lists in scope git-diff.
     *
     * @return list<array<string, mixed>>
     */
    private function staleIn(PDO $pdo): array
    {
        $projectId = (string) $pdo->query('SELECT id FROM projects')->fetchColumn();
        $queries = ArchitectureQueryService::forDatabase($pdo, gitWorkingTree: new ProcessGitWorkingTreeProvider());

        return $queries->services()->testFidelityQueries->auditTests($projectId, category: 'STALE_SINCE', scope: 'git-diff')->data['items'];
    }
}
