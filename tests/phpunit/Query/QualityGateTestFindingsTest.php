<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Result\ResultEnvelope;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;

use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * `quality_gate` budgets the definite test findings a change adds, counted as
 * keys the baseline did not have, and reports every active finding in SARIF.
 */
#[Group('test-fidelity')]
#[Group('typescript-scanner')]
final class QualityGateTestFindingsTest extends KnossosTestCase
{
    private const FIXTURE = '/tests/Fixtures/test-fidelity/ts-audit';
    private const BUDGET = ['new_definite_test_findings' => 0];
    private const RENAMED = "export class UserService {\n  getProfileV2(id: string): string {\n    return id;\n  }\n}\n";

    public function testANewStaleMockFailsAZeroBudget(): void
    {
        [$pdo, $service, $root] = $this->healthy();
        $baseline = $service->scan($root);
        file_put_contents($root . '/src/UserService.ts', self::RENAMED);
        $service->scan($root);

        $gate = $this->gate($pdo, $baseline->projectId, (string) $baseline->snapshotId, self::BUDGET);

        assertSame(false, $gate->data['passed']);
        assertSame(1, $gate->data['checks'][0]['actual']);
        assertSame(1, $gate->data['metrics']['new_definite_test_findings']);
    }

    public function testAMockThatOnlyMovedIsNotNew(): void
    {
        [$pdo, $service, $root] = $this->unhealthy();
        $baseline = $service->scan($root);
        $ghost = (string) file_get_contents($root . '/tests/ghost.test.ts');
        file_put_contents($root . '/tests/ghost.test.ts', "// a comment pushes every line down\n" . $ghost);
        $service->scan($root);

        $gate = $this->gate($pdo, $baseline->projectId, (string) $baseline->snapshotId, self::BUDGET);

        assertSame(0, $gate->data['checks'][0]['actual']);
        assertSame(true, $gate->data['passed']);
    }

    public function testABaselineWithoutARecordIsIndeterminate(): void
    {
        [$pdo, $service, $root] = $this->healthy();
        $baseline = $service->scan($root);
        file_put_contents($root . '/src/Extra.ts', "export const extra = 1;\n");
        $service->scan($root);
        $pdo->exec('UPDATE scan_snapshots SET test_findings_recorded = 0');
        $pdo->exec('DELETE FROM snapshot_test_findings');

        $gate = $this->gate($pdo, $baseline->projectId, (string) $baseline->snapshotId, self::BUDGET + ['new_cycles' => 0]);

        assertSame(false, $gate->data['passed']);
        $check = $gate->data['checks'][0];
        assertSame('new_definite_test_findings', $check['metric']);
        assertSame(true, $check['indeterminate']);
        assertSame('baseline_has_no_test_findings', $check['indeterminate_reason']);
        assertSame(false, $check['passed']);
        assertSame(true, $gate->data['checks'][1]['passed']);
    }

    public function testASuppressedFindingIsNotCounted(): void
    {
        [$pdo, $service, $root] = $this->healthy();
        $baseline = $service->scan($root);
        file_put_contents($root . '/src/UserService.ts', self::RENAMED);
        $service->scan($root);
        assertSame(1, $this->gate($pdo, $baseline->projectId, (string) $baseline->snapshotId, self::BUDGET)->data['metrics']['new_definite_test_findings']);

        file_put_contents($root . '/knossos.json', json_encode(['version' => 1, 'test_finding_suppressions' => [['rule' => 'GHOST_MEMBER']]]));

        $gate = $this->gate($pdo, $baseline->projectId, (string) $baseline->snapshotId, self::BUDGET);
        assertSame(0, $gate->data['metrics']['new_definite_test_findings']);
        assertSame(true, $gate->data['passed']);
    }

    public function testASuppressionAlsoClearsTheBaselineSide(): void
    {
        [$pdo, $service, $root] = $this->unhealthy();
        $baseline = $service->scan($root);
        file_put_contents($root . '/src/Extra.ts', "export const extra = 1;\n");
        $service->scan($root);
        // The ghost is the baseline's; once suppressed, only the rest is counted on either side.
        file_put_contents($root . '/knossos.json', json_encode(['version' => 1, 'test_finding_suppressions' => [['rule' => 'GHOST_MEMBER']]]));

        assertSame(0, $this->gate($pdo, $baseline->projectId, (string) $baseline->snapshotId, self::BUDGET)->data['metrics']['new_definite_test_findings']);
    }

    public function testWarningsNeverCountTowardTheBudget(): void
    {
        [$pdo, $service, $root] = $this->healthy();
        $baseline = $service->scan($root);
        $this->addWarningOnly($root);
        $service->scan($root);

        $gate = $this->gate($pdo, $baseline->projectId, (string) $baseline->snapshotId, self::BUDGET, true);

        assertSame(0, $gate->data['metrics']['new_definite_test_findings']);
        assertSame(true, $gate->data['passed']);
        $findings = array_filter($gate->data['sarif']['runs'][0]['results'], static fn(array $r): bool => str_starts_with($r['ruleId'], 'test.fidelity.'));
        assertSame(['warning'], array_values(array_unique(array_column($findings, 'level'))));
    }

    public function testSarifCarriesTestFindingsWithTheirLevel(): void
    {
        [$pdo, $service, $root] = $this->healthy();
        $baseline = $service->scan($root);
        $this->addWarningOnly($root);
        file_put_contents($root . '/src/UserService.ts', self::RENAMED);
        $service->scan($root);

        $all = $this->gate($pdo, $baseline->projectId, (string) $baseline->snapshotId, self::BUDGET, true)->data['sarif']['runs'][0]['results'];
        // The scan's own diagnostics follow the findings, with their own rule ids.
        $results = array_values(array_filter($all, static fn(array $r): bool => str_starts_with($r['ruleId'], 'test.fidelity.')));
        assertSame(['test.fidelity.ghost_member.v1/missing_member', 'test.fidelity.visibility_breach.v1/visibility'], array_column($results, 'ruleId'));
        assertSame('knossos.', substr($all[2]['ruleId'], 0, 8), 'policy violations come first, then findings, then diagnostics');

        $uri = static fn(array $r): string => $r['locations'][0]['physicalLocation']['artifactLocation']['uri'];
        $line = static fn(array $r): int => $r['locations'][0]['physicalLocation']['region']['startLine'];
        $definite = array_values(array_filter($results, static fn(array $r): bool => $r['level'] === 'error'));
        assertSame(1, count($definite));
        assertSame('tests/ghost.test.ts', $uri($definite[0]));
        assertSame(6, $line($definite[0]));
        assertSame('test.fidelity.ghost_member.v1/missing_member', $definite[0]['ruleId']);
        $warning = array_values(array_filter($results, static fn(array $r): bool => $r['level'] === 'warning'));
        assertSame(1, count($warning));
        assertSame('tests/vault.test.ts', $uri($warning[0]));
        assertSame(5, $line($warning[0]));
        assertNotSame('', $warning[0]['message']['text']);
    }

    /** @return array{0: PDO, 1: ProjectScanService, 2: string} a project whose one test double matches its code */
    private function healthy(): array
    {
        [$pdo, $service, $root] = $this->unhealthy();
        unlink($root . '/tests/planted.test.ts');
        file_put_contents($root . '/tests/ghost.test.ts', str_replace("'getProfil'", "'getProfile'", (string) file_get_contents($root . '/tests/ghost.test.ts')));

        return [$pdo, $service, $root];
    }

    /** @return array{0: PDO, 1: ProjectScanService, 2: string} the fixture as it is, with its stale and its vacuous doubles */
    private function unhealthy(): array
    {
        $root = self::scratchPath('ts-gate-test-findings');
        $this->copyTree(self::repositoryRoot() . self::FIXTURE, $root);
        $pdo = $this->freshTestDatabase();

        return [$pdo, new ProjectScanService($pdo, self::repositoryRoot(), [$root]), $root];
    }

    private function addWarningOnly(string $root): void
    {
        file_put_contents($root . '/src/Vault.ts', "export class Vault {\n  private secret(): number {\n    return 1;\n  }\n}\n");
        file_put_contents($root . '/tests/vault.test.ts', "import { it, vi } from 'vitest';\nimport { Vault } from '../src/Vault';\n\nit('spies on a private member', () => {\n  vi.spyOn(new Vault() as any, 'secret');\n});\n");
    }

    /** @param array<string, int> $budgets */
    private function gate(PDO $pdo, string $projectId, string $baseline, array $budgets, bool $sarif = false): ResultEnvelope
    {
        return ArchitectureQueryService::forDatabase($pdo)->services()->gateQueries->qualityGate($projectId, $baseline, $budgets, [], $sarif);
    }
}
