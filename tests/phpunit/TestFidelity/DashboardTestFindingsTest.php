<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity;

use Knossos\Query\DashboardService;
use Knossos\Scan\ProjectScanService;
use Knossos\TestFidelity\DashboardTestFindings;
use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * The dashboard's `test_findings` section over the Nemesis TypeScript fixture:
 * `UserService.spec.ts` holds one definite ghost member against
 * `UserService`, a warning return drift, a warning visibility breach and a
 * definite self comparison that replaces no production code.
 */
#[Group('test-fidelity')]
#[Group('typescript-scanner')]
final class DashboardTestFindingsTest extends KnossosTestCase
{
    private const FIXTURE = '/tests/Fixtures/test-fidelity/typescript/nemesis';

    /** @return array<string, mixed> the dashboard's `test_findings`, for a project holding the Nemesis tests, a knossos.json of `$config` when scanned and of `$editedAfterTheScan` when read */
    private function section(?string $config = null, ?string $editedAfterTheScan = null): array
    {
        $root = self::scratchPath('dashboard-test-findings');
        $this->copyTree(self::repositoryRoot() . self::FIXTURE . '/src', $root . '/src');
        $this->copyTree(self::repositoryRoot() . self::FIXTURE . '/tests', $root . '/tests');
        if ($config !== null) {
            file_put_contents($root . '/knossos.json', $config);
        }
        $pdo = $this->freshTestDatabase();
        (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);

        if ($editedAfterTheScan !== null) {
            file_put_contents($root . '/knossos.json', $editedAfterTheScan);
        }

        return (new DashboardService($pdo))->dashboard($root)['test_findings'];
    }

    public function testFindingsAreGroupedByTheProductionFileTheyStandIn(): void
    {
        $section = $this->section();

        assertSame([2, 2], [$section['definite'], $section['warning']]);
        assertSame(['src/UserService.ts'], array_keys($section['by_production_file']));
        $group = $section['by_production_file']['src/UserService.ts'];
        assertSame([1, 'UserService'], [$group['doubles'], $group['target']]);
        assertCount(1, $group['keys']);
        assertSame(['getProfil(): ghost'], $group['reasons']);
        assertSame(false, $section['by_production_file_truncated']);
    }

    public function testWarningsStayOutOfTheProductionGroups(): void
    {
        $section = $this->section();
        $grouped = array_merge(...array_column($section['by_production_file'], 'keys'));
        $warnings = array_column(array_filter($section['list'], static fn(array $item): bool => $item['confidence'] === 'warning'), 'key');

        assertCount(2, $warnings);
        assertSame([], array_values(array_intersect($grouped, $warnings)));
    }

    public function testTheListPutsDefiniteFirst(): void
    {
        $section = $this->section();

        assertSame(['definite', 'definite', 'warning', 'warning'], array_column($section['list'], 'confidence'));
        assertSame([['tests/UserService.spec.ts', 7], ['tests/UserService.spec.ts', 32], ['tests/UserService.spec.ts', 17], ['tests/UserService.spec.ts', 23]], array_map(static fn(array $item): array => [$item['file'], $item['line']], $section['list']));
        assertSame(['src/UserService.ts', null, 'src/UserService.ts', 'src/UserService.ts'], array_column($section['list'], 'target_file'));
        assertSame(false, $section['list_truncated']);
        assertSame(['key', 'category', 'sub_rule', 'confidence', 'message', 'file', 'line', 'target_file'], array_keys($section['list'][0]));
    }

    /**
     * One definite finding of a double of `$target`, in the shape the shared reader returns.
     *
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private static function finding(string $key, string $canonical, string $name, array $attributes, string $message = 'm', string $category = 'GHOST_MEMBER', string $file = 'src/a.ts'): array
    {
        return ['key' => $key, 'double_id' => 'double-' . $key, 'confidence' => 'definite', 'category' => $category, 'message' => $message, 'attributes' => $attributes, 'target_canonical' => $canonical, 'target_name' => $name, 'target_file' => $file];
    }

    public function testTheReasonsMemberIsTheStoredOneThenTheCanonicalLastSegmentThenTheMessage(): void
    {
        $group = DashboardTestFindings::byProductionFile([
            // The stored member wins over a canonical name that has a member segment and over the message.
            self::finding('a', 'src/a.ts#Gateway::refund', 'Gateway', ['member' => 'charge'], 'x stubs void, which Gateway no longer has'),
            // No stored member: the canonical name's last segment.
            self::finding('b', 'src/a.ts#Gateway::capture', 'Gateway', [], 'x stubs void, which Gateway no longer has', 'ARITY_MISMATCH'),
            // Neither: the member the message names.
            self::finding('c', 'src/a.ts#Gateway', 'Gateway', [], 'x stubs settle, which Gateway no longer has', 'RETURN_DRIFT'),
            // None at all: the category alone.
            self::finding('d', 'src/a.ts#Gateway', 'Gateway', [], 'm', 'MOCKS_SUBJECT', 'src/b.ts'),
        ]);

        assertSame(['capture(): arity', 'charge(): ghost', 'settle(): return'], $group['src/a.ts']['reasons']);
        assertSame(['mocks subject'], $group['src/b.ts']['reasons']);
    }

    public function testTheMostCommonOwnerNamesTheFileAndATieGoesToTheFirstAlphabetically(): void
    {
        $owners = static fn(array ...$findings): string => DashboardTestFindings::byProductionFile($findings)['src/a.ts']['target'];

        assertSame('Beta', $owners(
            self::finding('1', 'src/a.ts#Alpha::x', 'Alpha', []),
            self::finding('2', 'src/a.ts#Beta::x', 'Beta', []),
            self::finding('3', 'src/a.ts#Beta::y', 'Beta', []),
        ));
        assertSame('Alpha', $owners(
            self::finding('1', 'src/a.ts#Beta::x', 'Beta', []),
            self::finding('2', 'src/a.ts#Alpha::x', 'Alpha', []),
        ));
    }

    public function testSuppressionsAreApplied(): void
    {
        $suppressed = $this->section((string) json_encode(['version' => 1, 'test_finding_suppressions' => [['rule' => 'GHOST_MEMBER']]]));
        assertSame([1, 2, null], [$suppressed['definite'], $suppressed['warning'], $suppressed['suppressions_invalid']]);
        assertSame([], $suppressed['by_production_file']);
    }

    public function testAnInvalidConfigIsSaidNotSwallowed(): void
    {
        $section = $this->section(null, (string) json_encode(['version' => 1, 'test_finding_suppressions' => [['rule' => '']]]));

        assertSame('PROJECT_CONFIG_INVALID: test_finding_suppressions entries need a rule.', $section['suppressions_invalid']);
        assertSame([2, 2], [$section['definite'], $section['warning']]);
    }
}
