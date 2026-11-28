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
 * 6. `packageMock.spec.ts:11`: both tools are silent on a spy of `node:fs`;
 *    Knossos is too, as code outside the project may reach a package's double.
 * 7. `dogfood-clean/tests/catalog.test.ts:1`: Momus's MOCK-001 (over-mocked)
 *    is no scan finding, and MOCKS_SUBJECT fires only on a mocked sole or
 *    named-for subject. MOCK-001 is the on-request OVER_MOCKING, which fires
 *    there too; `OnRequestAcceptanceTest` compares it and numbers its
 *    departures.
 * 8. `UserService.spec.ts:23`: Nemesis reports the spy on a `private` method
 *    as a definite visibility breach, and the spec maps VISIBILITY_BREACH to
 *    definite. TypeScript's `private` and `protected` hold at compile time
 *    only, so `vi.spyOn(s as any, 'secret')` does replace the method; Knossos
 *    reports a `warning` that the double bypasses the public interface. An
 *    ECMAScript `#name` member, which no double can reach, stays `definite`.
 *    A deviation from the spec's confidence mapping, decided in review.
 * 9. Nemesis over `momus/` (frozen in phase 6, not part of either golden
 *    above): it agrees on the ghost at `ledger.test.ts:16`; it gives two
 *    definite return drifts at `assignability.test.ts:36`, the spy of the
 *    `mockReturnValueOnce('nope')` that Knossos holds at `:37`, and resolves
 *    neither stub at `:9` nor `:30`, where Momus's outcome stands; its arity
 *    findings in `signature.fixture.ts` fall outside the golden (item 3).
 *
 * The frozen Momus and Nemesis output these lists come from is in
 * `tests/Fixtures/test-fidelity/goldens/{momus,nemesis}/typescript.json`.
 */
#[Group('test-fidelity')]
#[Group('typescript-scanner')]
final class TypeScriptAcceptanceTest extends KnossosTestCase
{
    use FrozenGoldens;
    private const FIXTURES = '/tests/Fixtures/test-fidelity/typescript';

    private const NEMESIS = ['nemesis/src' => 'src', 'nemesis/tests' => 'tests'];

    /**
     * What the frozen Momus run over each fixture reports (`goldens/momus/typescript.json`),
     * as `RULE/severity@file:line`: the runs this docblock describes.
     */
    private const MOMUS_GOLDEN = [
        'momus' => [
            'DRIFT-001/error@tests/ledger.test.ts:16',
            'DRIFT-003/warning@tests/assignability.test.ts:30',
            'DRIFT-003/warning@tests/assignability.test.ts:37',
            'DRIFT-003/warning@tests/assignability.test.ts:9',
            'TAUT-002/error@tests/before-each.test.ts:18',
            'TAUT-002/error@tests/before-each.test.ts:22',
            'TAUT-002/error@tests/before-each.test.ts:39',
            'TAUT-002/error@tests/before-each.test.ts:49',
            'TAUT-002/error@tests/ledger.test.ts:11',
            'TAUT-004/warning@tests/ledger.test.ts:39',
            'TAUT-006/warning@tests/ledger.test.ts:39',
        ],
        'nemesis' => [
            'DRIFT-001/error@tests/UserService.spec.ts:7',
            'TAUT-001/error@tests/UserService.spec.ts:32',
            'TAUT-003/error@tests/UserService.spec.ts:32',
        ],
        'nemesis/dogfood-clean' => [
            'MOCK-001/warning@tests/catalog.test.ts:1',
        ],
    ];

    /** The same for the frozen Nemesis run (`goldens/nemesis/typescript.json`), as `TYPE/confidence@file:line`. */
    private const NEMESIS_GOLDEN = [
        'momus' => [
            'ARITY_MISMATCH/definite@tests/signature.fixture.ts:6',
            'ARITY_MISMATCH/definite@tests/signature.fixture.ts:9',
            'GHOST_METHOD/definite@tests/ledger.test.ts:16',
            'RETURN_DRIFT/definite@tests/assignability.test.ts:36',
            'RETURN_DRIFT/definite@tests/assignability.test.ts:36',
        ],
        'nemesis' => [
            'ARITY_MISMATCH/definite@tests/UserService.spec.ts:29',
            'GHOST_METHOD/definite@tests/UserService.spec.ts:7',
            'RETURN_DRIFT/warning@tests/UserService.spec.ts:17',
            'RETURN_DRIFT/warning@tests/UserService.spec.ts:17',
            'VISIBILITY_BREACH/definite@tests/UserService.spec.ts:23',
        ],
        'nemesis/dogfood-clean' => [],
    ];

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
            'VISIBILITY_BREACH/visibility/warning@tests/UserService.spec.ts:23',
            'ARITY_MISMATCH/call_arity/definite@tests/UserService.spec.ts:29',
            'TAUTOLOGY/self_comparison/definite@tests/UserService.spec.ts:32',
        ]), self::findings($pdo));
    }

    public function testDogfoodCleanIsSilent(): void
    {
        [$pdo] = $this->scanned(['nemesis/dogfood-clean' => '']);

        assertSame([], self::findings($pdo));
    }

    /**
     * A NodeNext project writes `.js`, `.jsx` and `.mjs` for the sources it
     * compiles (`.ts`, `.tsx`, `.mts`), in mocks as in imports. The healthy
     * mocks are silent; a factory key the module lacks and a module no source
     * names are the ghosts.
     */
    public function testNodeNextMocksResolveTheirJsSpecifierToTheTypeScriptSource(): void
    {
        [$pdo] = $this->scanned(['nodenext' => '']);

        assertSame(self::sorted([
            'GHOST_MEMBER/missing_module/definite@tests/gone.test.ts:3',
            'GHOST_MEMBER/missing_export/definite@tests/stale.test.ts:3',
        ]), self::findings($pdo));
    }

    public function testFindingsAreDeterministic(): void
    {
        $ids = static fn(PDO $pdo): array => $pdo->query('SELECT id FROM test_findings ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        [$first, , $root] = $this->scanned(['momus' => '']);
        $second = $this->freshTestDatabase();
        (new ProjectScanService($second, self::repositoryRoot(), [$root]))->scan($root);

        assertCount(count(self::findings($first)), $ids($first));
        self::assertNotEmpty($ids($first), 'A determinism test over zero findings proves nothing.');
        assertSame($ids($first), $ids($second));
    }

    /**
     * Findings follow an edit to the target, in both directions, though the
     * test file is not edited. The worker's read set reaches the target, so
     * the test file is parsed again (directly, through a barrel, or through
     * a base class in another file alike); a test file the edit did not
     * reach is reused and keeps its findings. That a reused contribution is
     * judged against a changed one is pinned in ReusedContributionTest.
     */
    public function testFindingsFollowAnEditToTheTargetInBothDirections(): void
    {
        [$pdo, $service, $root] = $this->scanned(['momus' => '']);
        $before = self::findings($pdo);
        $production = $root . '/src/services/invoice.ts';
        $original = (string) file_get_contents($production);

        file_put_contents($production, str_replace('totalCents(): number', 'totalCents(): string', $original));
        $changed = $service->scan($root);
        $drift = array_values(array_filter(self::findings($pdo), static fn(string $finding): bool => str_starts_with($finding, 'RETURN_DRIFT/')));

        // The edited file and the test that reads it were parsed again; the rest were reused.
        assertSame([2, 10], [$changed->data['parsed_files'], $changed->data['unchanged_files']]);
        assertSame(['RETURN_DRIFT/return_type/definite@tests/assignability.test.ts:16'], $drift);
        assertSame(array_values(array_filter($before, static fn(string $finding): bool => !str_contains($finding, 'assignability'))), array_values(array_filter(self::findings($pdo), static fn(string $finding): bool => !str_contains($finding, 'assignability'))));

        file_put_contents($production, $original);
        $service->scan($root);

        assertSame($before, self::findings($pdo));
    }

    /** A member renamed in the target makes its double a ghost, and renaming it back leaves the arity finding the spec holds there. */
    public function testAGhostFollowsARenameOfTheTargetInBothDirections(): void
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
        assertSame(['ARITY_MISMATCH/call_arity/definite@tests/UserService.spec.ts:29'], $at29());
    }

    /**
     * The frozen goldens still hold the tool runs this docblock describes;
     * `OnRequestAcceptanceTest` checks each against its fixture and commit.
     */
    public function testTheFrozenGoldensHoldWhatThisDocblockDescribes(): void
    {
        assertSame(self::MOMUS_GOLDEN, self::goldenFindings('momus', 'typescript'));
        assertSame(self::NEMESIS_GOLDEN, self::goldenFindings('nemesis', 'typescript'));
        assertSame([self::MOMUS_COMMIT, self::NEMESIS_COMMIT], [self::golden('momus', 'typescript')['commit'], self::golden('nemesis', 'typescript')['commit']]);
    }
}
