<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity;

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
 * and SARIF, and `snapshot_diff`.
 *
 * A planted test file that only `OVER_MOCKING` judges leaves each of them as
 * it was. The plant is proved live first (preflight F16): `audit_tests` with
 * `tier: "on_request"` lists it, so the equality is not one of two empty answers.
 */
#[Group('test-fidelity')]
#[Group('typescript-scanner')]
final class OnRequestIsolationTest extends KnossosTestCase
{
    private const FIXTURE = '/tests/Fixtures/test-fidelity/typescript/nemesis';
    private const PLANT = 'tests/overMocked.spec.ts';

    private const CONTROL = 'src/Notes.ts';

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
            $pdo = $this->freshTestDatabase();
            $baseline = (string) (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root)->snapshotId;
            // Without the plant: a turn that adds a production file, so the gate and the diff have a later snapshot to read.
            file_put_contents($root . '/' . self::CONTROL, "export const NOTE = 1;\n");
            $without = $this->surfaces($pdo, $root, $baseline, self::CONTROL);
            self::assertSame([], $this->onRequestIn($pdo, self::PLANT));

            file_put_contents($root . '/' . self::PLANT, self::PLANTED);
            $with = $this->surfaces($pdo, $root, $baseline, self::PLANT);

            // The plant is live: on request, it is over-mocked.
            self::assertSame([[self::PLANT, 1, 'OVER_MOCKING', 'warning']], array_map(
                static fn(array $item): array => [$item['file'], $item['line'], $item['category'], $item['confidence']],
                $this->onRequestIn($pdo, self::PLANT),
            ));
            // Each turn took its file in: the brief rescanned it.
            foreach ([self::CONTROL, self::PLANT] as $file) {
                self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM files WHERE relative_path = '" . $file . "'")->fetchColumn(), $file);
            }
            // And no surface saw it.
            self::assertGreaterThanOrEqual(1, $without['count'], 'the fixture has stored findings to compare');
            foreach (array_keys($without) as $surface) {
                self::assertSame($without[$surface], $with[$surface], $surface);
            }
            self::assertSame([0, 0], array_values($with['snapshot_diff']['counts']));
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * Each surface's test-finding part, after a turn that reported `$file`.
     *
     * @return array<string, mixed>
     */
    private function surfaces(PDO $pdo, string $root, string $baseline, string $file): array
    {
        $brief = (new TurnBriefService($pdo, ':memory:', self::repositoryRoot()))->brief($root, [$file], [], true);
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
}
