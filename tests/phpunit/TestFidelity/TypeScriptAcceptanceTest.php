<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity;

use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * Acceptance against the tools test fidelity replaces: a real scan of the
 * Momus and Nemesis TypeScript fixtures stores what their own expectations
 * say, mapped onto Knossos's categories as `category/sub_rule/confidence@file:line`.
 *
 * Sources: Momus-MCP `test/golden/audit.test.ts` ("planted violations") over
 * `packages/parser-typescript/test/fixtures` (copied to `momus/`); Nemesis-MCP
 * `nemesis audit fixtures/ts --strictness=all` and `fixtures/dogfood-clean`
 * (copied to `nemesis/` and `nemesis/dogfood-clean/`).
 *
 * Disagreements, and where Knossos deliberately differs from a golden list:
 *
 * 1. `before-each`/`ledger.test.ts:39`: Momus reports TAUT-004 (mock_only) and
 *    TAUT-006 (unconfigured_spy) on one assertion. Knossos gives one finding
 *    per assertion, the narrower first, so only `unconfigured_spy`.
 * 2. `assignability.test.ts:30`: `mockImplementation(() => 'nope')` declares no
 *    return type, so the comparison rests on an inferred side and is a
 *    `warning`, as Momus's severity says; the drift at :9 and :37 pins a
 *    declared value and is `definite`.
 * 3. Momus's `*.fixture.ts` files are worker fixtures, not part of its golden;
 *    Knossos reads them as test code, so the Momus comparison is held to the
 *    `*.test.ts` files the golden list covers.
 * 4. `UserService.spec.ts:32` `expect(true).toBe(true)`: Momus reports TAUT-001
 *    and TAUT-003, Nemesis nothing (it has no tautology rules). It is a real
 *    tautology; Knossos gives one finding, `self_comparison`.
 * 5. `UserService.spec.ts:17`, `:23`: Nemesis reports return drift and a
 *    visibility breach, Momus nothing; Nemesis's outcome is expected. Nemesis
 *    gives one return-drift warning per missing field (two), Knossos one per
 *    pinned value.
 * 6. `UserService.spec.ts:29`: Nemesis reports ARITY_MISMATCH (definite) from
 *    `(spy as any).toHaveBeenCalledWith('1', {}, 'extra')`, a call vitest has
 *    no such method for. Knossos records no call-matcher arity on a double, so
 *    it is silent; open, not expected here.
 * 7. `packageMock.spec.ts:11`: both tools are silent on a spy of `node:fs`;
 *    Knossos is too, as code outside the project may reach a package's double.
 * 8. `dogfood-clean/tests/catalog.test.ts:1`: Momus reports MOCK-001
 *    (over-mocked), Nemesis nothing; Nemesis's silence is expected, and
 *    Knossos's MOCKS_SUBJECT fires only on a mocked sole or named-for subject.
 */
#[Group('test-fidelity')]
#[Group('typescript-scanner')]
final class TypeScriptAcceptanceTest extends KnossosTestCase
{
    private const FIXTURES = '/tests/Fixtures/test-fidelity/typescript';

    private const NEMESIS = ['nemesis/src' => 'src', 'nemesis/tests' => 'tests'];

    /**
     * A project made of copies of fixture directories (fixture path => path in
     * the project), scanned once.
     *
     * @param array<string, string> $trees
     * @return array{0: PDO, 1: ProjectScanService, 2: string}
     */
    private function scanned(array $trees): array
    {
        $root = self::scratchPath('ts-acceptance');
        foreach ($trees as $from => $to) {
            $this->copyTree(self::repositoryRoot() . self::FIXTURES . '/' . $from, rtrim($root . '/' . $to, '/'));
        }
        $pdo = $this->freshTestDatabase();
        $service = new ProjectScanService($pdo, self::repositoryRoot(), [$root]);
        $service->scan($root);

        return [$pdo, $service, $root];
    }

    /**
     * Every finding of the active scan as `category/sub_rule/confidence@file:line`, sorted.
     *
     * @return list<string>
     */
    private static function findings(PDO $pdo): array
    {
        $rows = $pdo->query(
            'SELECT f.category, f.sub_rule, f.confidence, fl.relative_path, f.start_line FROM test_findings f '
            . 'JOIN projects p ON p.id = f.project_id AND f.last_scan_id = p.active_scan_id JOIN files fl ON fl.id = f.file_id',
        )->fetchAll(PDO::FETCH_NUM);

        return self::sorted(array_map(static fn(array $row): string => vsprintf('%s/%s/%s@%s:%d', $row), $rows));
    }

    /**
     * @param list<string> $list
     * @return list<string>
     */
    private static function sorted(array $list): array
    {
        sort($list);

        return $list;
    }

    public function testMomusPlantedViolations(): void
    {
        [$pdo] = $this->scanned(['momus' => '']);
        $golden = array_values(array_filter(self::findings($pdo), static fn(string $finding): bool => str_contains($finding, '.test.ts:')));

        assertSame(self::sorted([
            'GHOST_MEMBER/missing_member/definite@tests/ledger.test.ts:16',
            'RETURN_DRIFT/return_type/definite@tests/assignability.test.ts:9',
            'RETURN_DRIFT/return_type/warning@tests/assignability.test.ts:30',
            'RETURN_DRIFT/return_type/definite@tests/assignability.test.ts:37',
            'TAUTOLOGY/mock_echo/definite@tests/ledger.test.ts:11',
            'TAUTOLOGY/mock_echo/definite@tests/before-each.test.ts:18',
            'TAUTOLOGY/mock_echo/definite@tests/before-each.test.ts:22',
            'TAUTOLOGY/mock_echo/definite@tests/before-each.test.ts:39',
            'TAUTOLOGY/mock_echo/definite@tests/before-each.test.ts:49',
            'TAUTOLOGY/unconfigured_spy/warning@tests/ledger.test.ts:39',
        ]), $golden);
    }

    public function testMomusHealthyTwinIsSilent(): void
    {
        [$pdo] = $this->scanned(['momus' => '']);
        $healthy = array_filter(self::findings($pdo), static function (string $finding): bool {
            $line = (int) substr($finding, (int) strrpos($finding, ':') + 1);

            return str_contains($finding, '@tests/before-each.test.ts:') && $line >= 23 && $line <= 27;
        });

        assertSame([], array_values($healthy));
    }

    public function testNemesisTsFixtures(): void
    {
        [$pdo] = $this->scanned(self::NEMESIS);

        assertSame(self::sorted([
            'GHOST_MEMBER/missing_member/definite@tests/UserService.spec.ts:7',
            'RETURN_DRIFT/return_type/warning@tests/UserService.spec.ts:17',
            'VISIBILITY_BREACH/visibility/definite@tests/UserService.spec.ts:23',
            'TAUTOLOGY/self_comparison/definite@tests/UserService.spec.ts:32',
        ]), self::findings($pdo));
    }

    public function testDogfoodCleanIsSilent(): void
    {
        [$pdo] = $this->scanned(['nemesis/dogfood-clean' => '']);

        assertSame([], self::findings($pdo));
    }

    public function testFindingsAreDeterministic(): void
    {
        $ids = static fn(PDO $pdo): array => $pdo->query('SELECT id FROM test_findings ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        [$first, , $root] = $this->scanned(['momus' => '']);
        $second = $this->freshTestDatabase();
        (new ProjectScanService($second, self::repositoryRoot(), [$root]))->scan($root);

        assertCount(count(self::findings($first)), $ids($first));
        assertSame($ids($first), $ids($second));
    }

    /**
     * Findings are judged over every contribution, reused ones included: a
     * test file left as it was is held to its production file as that file
     * is now, and a test file the change did not reach keeps its findings.
     */
    public function testAnUntouchedTestFileIsJudgedAgainstItsChangedTarget(): void
    {
        [$pdo, $service, $root] = $this->scanned(['momus' => '']);
        $before = self::findings($pdo);
        $production = $root . '/src/services/invoice.ts';
        $original = (string) file_get_contents($production);

        file_put_contents($production, str_replace('totalCents(): number', 'totalCents(): string', $original));
        $changed = $service->scan($root);
        $drift = array_values(array_filter(self::findings($pdo), static fn(string $finding): bool => str_starts_with($finding, 'RETURN_DRIFT/')));

        // Only the edited file and the test that reads it were parsed again.
        assertSame([2, 10], [$changed->data['parsed_files'], $changed->data['unchanged_files']]);
        assertSame(['RETURN_DRIFT/return_type/definite@tests/assignability.test.ts:16'], $drift);
        assertSame(array_values(array_filter($before, static fn(string $finding): bool => !str_contains($finding, 'assignability'))), array_values(array_filter(self::findings($pdo), static fn(string $finding): bool => !str_contains($finding, 'assignability'))));

        file_put_contents($production, $original);
        $service->scan($root);

        assertSame($before, self::findings($pdo));
    }

    public function testAnUntouchedTestFileFindsTheGhostOfARenamedMember(): void
    {
        [$pdo, $service, $root] = $this->scanned(self::NEMESIS);
        $production = $root . '/src/UserService.ts';
        $original = (string) file_get_contents($production);
        $at29 = static fn(): array => array_values(array_filter(self::findings($pdo), static fn(string $finding): bool => str_ends_with($finding, ':29')));

        file_put_contents($production, str_replace('updateProfile(', 'patchProfile(', $original));
        $service->scan($root);
        assertSame(['GHOST_MEMBER/missing_member/definite@tests/UserService.spec.ts:29'], $at29());

        file_put_contents($production, $original);
        $service->scan($root);
        assertSame([], $at29());
    }
}
