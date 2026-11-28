<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Acceptance against the tools test fidelity replaces, for Python: a real
 * scan of the Momus and Nemesis Python fixtures stores what those tools
 * report on them, mapped onto Knossos's categories as
 * `category/sub_rule/confidence@file:line`, beside a tautology golden of
 * Knossos's own.
 *
 * The lists come from running both tools on copies of the fixtures
 * (Momus-MCP 9fe7283, Nemesis-MCP bf9be27):
 *
 * - Momus over `momus-drift/` and `momus-src-layout/` (its
 *   `packages/parser-python/test/fixtures/drift` and `src-layout`), with a
 *   `.momusrc` of `{"languages":{"python":true},"cache":{"enabled":false}}`
 *   in the copy, since Momus audits Python only when told to:
 *   `npx tsx packages/cli/src/index.ts audit --root <copy> --max-issues 1000`.
 *   Over `momus-drift/` it gives exactly its own golden, "python drift
 *   fixtures" in `test/golden/audit.test.ts`.
 * - Nemesis over each Nemesis fixture (`nemesis/` is `fixtures/python`,
 *   `nemesis-experiments/<name>/` is `fixtures/experiments/python/<name>`),
 *   run from inside the copy, as its CLI resolves paths under the working
 *   directory: `node dist/cli/main.js audit . --strictness=all`.
 * - Each tool was also run over the other's fixtures and over `tautology/`,
 *   to find where they disagree. Where they do, Nemesis's outcome is expected.
 *
 * Momus's DRIFT-001 and Nemesis's GHOST_METHOD are
 * `GHOST_MEMBER/missing_member`, and on a module `missing_export`, as is
 * Momus's DRIFT-005; DRIFT-003 is `RETURN_DRIFT/return_type`; TAUT-001
 * `TAUTOLOGY/self_comparison`, TAUT-002 `mock_echo`, TAUT-003 `constant`,
 * TAUT-004 `mock_only`, TAUT-005 `zero_reach` and TAUT-006
 * `unconfigured_spy`; an ARITY_MISMATCH is `call_arity` for a matcher and
 * `signature` for a callable.
 *
 * Disagreements, where Knossos deliberately differs, and the gaps (cases a
 * tool reports that Knossos has no rule for yet: items 9 to 12):
 *
 * 1. `momus-drift` `drift_test.py:12` and `nemesis-experiments/module-drift`
 *    `test_mailer.py:10`: Nemesis reports definite drift, Momus warns (or,
 *    at `:10`, is silent). A Python pin's drift is a warning only (user
 *    decision): no runtime check refuses a mismatched `return_value`.
 * 2. `momus-drift` `drift_test.py:17`: Momus warns from a pyright-inferred
 *    `int`, Nemesis that `count` declares no return type, so the contract
 *    cannot be verified. Knossos holds a pin against a declared annotation
 *    only, and is silent where there is none.
 * 3. `momus-drift` `test_patch_imports.py:29`, `test_patch_missing.py:5` and
 *    `momus-src-layout` `tests/test_patch_missing.py:5`: Nemesis leaves these
 *    string targets unresolved and reports no outcome, so Momus's definite
 *    `missing_export` stands. The import bindings at
 *    `test_patch_imports.py:5`-`:20` are module attributes, silent in both.
 * 4. `nemesis-experiments/kwargs` `test_session.py:8`, and `tautology`
 *    `test_mock_only.py:9` and `test_unconfigured_spy.py:10`: Momus reports
 *    TAUT-004 and TAUT-006 on one assertion; Knossos gives one finding. A
 *    Python spy is a `Mock(wraps=…)` or a `mocker.spy`, so a `Mock(spec=…)`
 *    gives `mock_only` and the `wraps=` double `unconfigured_spy`.
 * 5. `nemesis-experiments/kwargs` `test_session.py:8`: Nemesis's definite
 *    `call_arity`, which Momus does not check. Matcher arity is definite
 *    without autospec (user decision), and silent for a call that
 *    includes `self`.
 * 6. `nemesis-experiments/side-effect` `test_mailer.py:7` and
 *    `inherited-collision` `test_x.py:8`: Nemesis reports a `side_effect`
 *    lambda of the wrong arity as definite. Knossos warns (user decision):
 *    the lambda raises only if something calls it. Momus is silent.
 * 7. `nemesis-experiments/spec` `test_session.py:7` and `autospec`
 *    `test_search.py:7`: Momus's TAUT-005 on a double that
 *    `assert client is not None` names. A double an assertion names is not
 *    zero-reach, in every language: silent. Nemesis has no tautology rules.
 * 8. `zero_reach` warnings neither tool gives: `inherited-collision`
 *    `test_x.py:8`, `module-drift` `test_mailer.py:10`, `property`
 *    `test_account.py:7`, `side-effect` `test_mailer.py:7` and `spec`
 *    `test_session.py:13`. Each patch pins a value nothing reaches, in a
 *    file whose tests run no production code, which is TAUT-005; Momus reads
 *    it only on a local `Mock`. A double answers to every test of its file
 *    (a Python double names no test case yet, G4-11), so the pinned patches
 *    at `patch` `test_payment.py:12` and `nemesis` `test_auth.py:21`, beside
 *    a test that runs production, are silent.
 * 9. A gap: `nemesis-experiments/property` `test_account.py:7`: Nemesis
 *    reports a `@property` patched without `PropertyMock`. No language has
 *    an accessor rule yet: silent but for item 8's warning.
 * 10. A gap: Momus's MOCK-002 (info) at `kwargs` `test_session.py:7` and
 *    `patch-multiple` `test_vault.py:6`. These tests run no production code,
 *    so `MOCKS_SUBJECT` would not speak, and for Python it is dormant until
 *    a double names its test case (G4-11).
 * 11. A gap: a `Mock(spec=…)` member's pin read back
 *    (`ledger.total.return_value = 7` then `assert ledger.total() == 7`):
 *    Momus reports TAUT-002; the worker gives a spec'd double no pinned
 *    value, so Knossos is silent. The golden's echo uses a plain `Mock()`.
 * 12. A gap (Ruling P10): an unspec'd `Mock()` pinned and never reached:
 *    Momus reports TAUT-005; an unspec'd double records no members
 *    (Decision 2), so Knossos is silent. The golden's stub is spec'd.
 * 13. Momus's MOCK-001 (over-mocking, at line 1 of
 *    `test_patch_imports.py`, `test_patch_missing.py`, both
 *    `momus-src-layout` tests, and the `module-drift`, `patch-multiple` and
 *    `side-effect` tests) is the on-request OVER_MOCKING, which fires on each;
 *    `OnRequestAcceptanceTest` compares it and numbers its departures.
 *
 * Every definite in these lists is one a tool gives too: the tautology
 * golden's three agree with Momus's errors there (TAUT-001 to TAUT-003),
 * and each of them passes when run (pytest 9.1, Python 3.14), as only an
 * assertion that cannot fail does; the planted `unconfigured_spy` fails.
 *
 * The frozen output of every run above is in
 * `tests/Fixtures/test-fidelity/goldens/{momus,nemesis}/python.json`.
 *
 * Rulings these fixtures hold no case of, pinned by their own tests
 * (`PythonHealthyIdiomsTest`, the worker's pytest suite):
 * - P3: a dunder is never judged.
 * - P7: a package's exports are open.
 * - P9/P19: an unseen base opens the member list; `Generic` and `Protocol` keep it closed.
 * - P20: a self-comparison is definite under `==` only between literals;
 *   any other `x == x` is never definite, and only `x is x` can be.
 * - The R12/R14 analogs: a `self.` attribute is never a literal, and a held double carries no pinned value.
 * - P21 and G4-12: a class whose methods a metaclass or base hook may rewrite (`methods_rewritable`) silences arity and drift.
 * - Decision 2: an unspec'd double records no members.
 */
#[Group('test-fidelity')]
#[Group('python-scanner')]
final class PythonAcceptanceTest extends KnossosTestCase
{
    use FrozenGoldens;
    private const FIXTURES = '/tests/Fixtures/test-fidelity/python';

    /**
     * What the frozen Momus run over each fixture reports (`goldens/momus/python.json`),
     * as `RULE/severity@file:line`: the runs this docblock describes.
     */
    private const MOMUS_GOLDEN = [
        'momus-drift' => [
            'DRIFT-001/error@drift_test.py:6',
            'DRIFT-003/warning@drift_test.py:12',
            'DRIFT-003/warning@drift_test.py:17',
            'DRIFT-005/error@test_patch_imports.py:29',
            'DRIFT-005/error@test_patch_missing.py:5',
            'MOCK-001/warning@test_patch_imports.py:1',
            'MOCK-001/warning@test_patch_missing.py:1',
            'TAUT-005/warning@drift_test.py:11',
            'TAUT-005/warning@drift_test.py:16',
            'TAUT-005/warning@healthy_test.py:11',
        ],
        'momus-src-layout' => [
            'DRIFT-005/error@tests/test_patch_missing.py:5',
            'MOCK-001/warning@tests/test_patch_existing.py:1',
            'MOCK-001/warning@tests/test_patch_missing.py:1',
        ],
        'nemesis' => [
            'DRIFT-001/error@tests/test_auth.py:9',
        ],
        'tautology' => [
            'TAUT-001/error@tests/test_self_comparison.py:6',
            'TAUT-002/error@tests/test_mock_echo.py:9',
            'TAUT-003/error@tests/test_constant.py:5',
            'TAUT-004/warning@tests/test_mock_only.py:9',
            'TAUT-004/warning@tests/test_unconfigured_spy.py:10',
            'TAUT-005/warning@tests/test_zero_reach.py:9',
            'TAUT-006/warning@tests/test_mock_only.py:9',
            'TAUT-006/warning@tests/test_unconfigured_spy.py:10',
        ],
        'nemesis-experiments/autospec' => [
            'TAUT-005/warning@tests/test_search.py:7',
        ],
        'nemesis-experiments/inherited-collision' => [],
        'nemesis-experiments/kwargs' => [
            'MOCK-002/info@tests/test_session.py:7',
            'TAUT-004/warning@tests/test_session.py:8',
            'TAUT-006/warning@tests/test_session.py:8',
        ],
        'nemesis-experiments/module-drift' => [
            'DRIFT-005/error@tests/test_mailer.py:6',
            'MOCK-001/warning@tests/test_mailer.py:1',
        ],
        'nemesis-experiments/patch' => [],
        'nemesis-experiments/patch-multiple' => [
            'DRIFT-001/error@tests/test_vault.py:6',
            'MOCK-001/warning@tests/test_vault.py:1',
            'MOCK-002/info@tests/test_vault.py:6',
        ],
        'nemesis-experiments/property' => [],
        'nemesis-experiments/side-effect' => [
            'MOCK-001/warning@tests/test_mailer.py:1',
        ],
        'nemesis-experiments/spec' => [
            'DRIFT-001/error@tests/test_session.py:13',
            'TAUT-005/warning@tests/test_session.py:7',
        ],
    ];

    /** The same for the frozen Nemesis run (`goldens/nemesis/python.json`), as `TYPE/confidence@file:line`. */
    private const NEMESIS_GOLDEN = [
        'momus-drift' => [
            'GHOST_METHOD/definite@drift_test.py:6',
            'RETURN_DRIFT/definite@drift_test.py:12',
            'RETURN_DRIFT/warning@drift_test.py:17',
        ],
        'momus-src-layout' => [],
        'nemesis' => [
            'GHOST_METHOD/definite@tests/test_auth.py:9',
        ],
        'tautology' => [],
        'nemesis-experiments/autospec' => [],
        'nemesis-experiments/inherited-collision' => [
            'ARITY_MISMATCH/definite@tests/test_x.py:8',
        ],
        'nemesis-experiments/kwargs' => [
            'ARITY_MISMATCH/definite@tests/test_session.py:8',
        ],
        'nemesis-experiments/module-drift' => [
            'GHOST_METHOD/definite@tests/test_mailer.py:6',
            'RETURN_DRIFT/definite@tests/test_mailer.py:10',
        ],
        'nemesis-experiments/patch' => [
            'GHOST_METHOD/definite@tests/test_payment.py:12',
        ],
        'nemesis-experiments/patch-multiple' => [
            'GHOST_METHOD/definite@tests/test_vault.py:6',
        ],
        'nemesis-experiments/property' => [
            'VISIBILITY_BREACH/definite@tests/test_account.py:7',
        ],
        'nemesis-experiments/side-effect' => [
            'ARITY_MISMATCH/definite@tests/test_mailer.py:7',
        ],
        'nemesis-experiments/spec' => [
            'GHOST_METHOD/definite@tests/test_session.py:13',
        ],
    ];

    /**
     * A project made of a copy of one fixture directory, scanned once.
     *
     * @return array{0: PDO, 1: ProjectScanService, 2: string}
     */
    private function scanned(string $fixture): array
    {
        $root = self::scratchPath('python-acceptance');
        $this->copyTree(self::repositoryRoot() . self::FIXTURES . '/' . $fixture, $root);
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

    /** Momus's own golden, less the departures above: `drift_test.py:12` warns, `:17` and the MOCK-001 lines are silent. */
    public function testMomusPythonFixtures(): void
    {
        [$pdo] = $this->scanned('momus-drift');

        assertSame(self::sorted([
            'GHOST_MEMBER/missing_member/definite@drift_test.py:6',
            'TAUTOLOGY/zero_reach/warning@drift_test.py:11',
            'RETURN_DRIFT/return_type/warning@drift_test.py:12',
            'TAUTOLOGY/zero_reach/warning@drift_test.py:16',
            'TAUTOLOGY/zero_reach/warning@healthy_test.py:11',
            'GHOST_MEMBER/missing_export/definite@test_patch_imports.py:29',
            'GHOST_MEMBER/missing_export/definite@test_patch_missing.py:5',
        ]), self::findings($pdo));
    }

    public function testMomusSrcLayout(): void
    {
        [$pdo] = $this->scanned('momus-src-layout');

        assertSame(['GHOST_MEMBER/missing_export/definite@tests/test_patch_missing.py:5'], self::findings($pdo));
    }

    public function testNemesisPythonFixtures(): void
    {
        [$pdo] = $this->scanned('nemesis');

        assertSame(['GHOST_MEMBER/missing_member/definite@tests/test_auth.py:9'], self::findings($pdo));
        assertSame(['login'], array_map(
            static fn(string $json): mixed => json_decode($json, true)['suggestion'] ?? null,
            $pdo->query('SELECT attributes_json FROM test_findings')->fetchAll(PDO::FETCH_COLUMN),
        ));
    }

    /** @return array<string, array{string, list<string>}> */
    public static function experiments(): array
    {
        return [
            'autospec' => ['autospec', []],
            'inherited-collision' => ['inherited-collision', [
                'ARITY_MISMATCH/signature/warning@tests/test_x.py:8',
                'TAUTOLOGY/zero_reach/warning@tests/test_x.py:8',
            ]],
            'kwargs' => ['kwargs', [
                'ARITY_MISMATCH/call_arity/definite@tests/test_session.py:8',
                'TAUTOLOGY/mock_only/warning@tests/test_session.py:8',
            ]],
            'module-drift' => ['module-drift', [
                'GHOST_MEMBER/missing_export/definite@tests/test_mailer.py:6',
                'RETURN_DRIFT/return_type/warning@tests/test_mailer.py:10',
                'TAUTOLOGY/zero_reach/warning@tests/test_mailer.py:10',
            ]],
            'patch' => ['patch', ['GHOST_MEMBER/missing_member/definite@tests/test_payment.py:12']],
            'patch-multiple' => ['patch-multiple', ['GHOST_MEMBER/missing_member/definite@tests/test_vault.py:6']],
            'property' => ['property', ['TAUTOLOGY/zero_reach/warning@tests/test_account.py:7']],
            'side-effect' => ['side-effect', [
                'ARITY_MISMATCH/signature/warning@tests/test_mailer.py:7',
                'TAUTOLOGY/zero_reach/warning@tests/test_mailer.py:7',
            ]],
            'spec' => ['spec', [
                'GHOST_MEMBER/missing_member/definite@tests/test_session.py:13',
                'TAUTOLOGY/zero_reach/warning@tests/test_session.py:13',
            ]],
        ];
    }

    /** @param list<string> $expected */
    #[DataProvider('experiments')]
    public function testNemesisExperiments(string $experiment, array $expected): void
    {
        [$pdo] = $this->scanned('nemesis-experiments/' . $experiment);

        assertSame(self::sorted($expected), self::findings($pdo));
    }

    /**
     * One planted case of each tautology beside its healthy twin, which is
     * silent. Momus gives each planted line too; the zero-reach twin sits in
     * a file of its own, as a double answers to every test of its file.
     */
    public function testKnossosTautologyGolden(): void
    {
        [$pdo] = $this->scanned('tautology');

        assertSame(self::sorted([
            'TAUTOLOGY/constant/definite@tests/test_constant.py:5',
            'TAUTOLOGY/mock_echo/definite@tests/test_mock_echo.py:9',
            'TAUTOLOGY/mock_only/warning@tests/test_mock_only.py:9',
            'TAUTOLOGY/self_comparison/definite@tests/test_self_comparison.py:6',
            'TAUTOLOGY/unconfigured_spy/warning@tests/test_unconfigured_spy.py:10',
            'TAUTOLOGY/zero_reach/warning@tests/test_zero_reach.py:9',
        ]), self::findings($pdo));
    }

    public function testFindingsAreDeterministic(): void
    {
        $ids = static fn(PDO $pdo): array => $pdo->query('SELECT id FROM test_findings ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        [$first, , $root] = $this->scanned('momus-drift');
        $second = $this->freshTestDatabase();
        (new ProjectScanService($second, self::repositoryRoot(), [$root]))->scan($root);

        assertCount(7, self::findings($first));
        assertCount(7, $ids($first));
        assertSame($ids($first), $ids($second));
        assertSame(self::findings($first), self::findings($second));
    }

    /**
     * Renaming a patched method in the production class makes the patch's
     * member a ghost, and renaming it back clears it; the test file is never
     * edited, and every other finding stays as it was. A third file, which
     * neither imports, is reused as it was.
     */
    public function testARenameInTheTargetMakesAndClearsAGhost(): void
    {
        [$pdo, $service, $root] = $this->scanned('nemesis');
        file_put_contents($root . '/src/clock.py', "def now() -> int:\n    return 0\n");
        assertSame(1, $service->scan($root)->data['parsed_files']);
        $before = self::findings($pdo);
        $production = $root . '/src/services.py';
        $test = (string) file_get_contents($root . '/tests/test_auth.py');
        $original = (string) file_get_contents($production);

        file_put_contents($production, str_replace('def login(', 'def sign_in(', $original));
        $renamed = $service->scan($root);

        // The edited file and the test that reads it to resolve its patch targets are parsed again, as in TypeScript; the unrelated file is not.
        assertSame([2, 1], [$renamed->data['parsed_files'], $renamed->data['unchanged_files']]);
        assertSame(self::sorted([...$before, 'GHOST_MEMBER/missing_member/definite@tests/test_auth.py:15']), self::findings($pdo));

        file_put_contents($production, $original);
        $service->scan($root);

        assertSame($before, self::findings($pdo));
        assertSame($test, (string) file_get_contents($root . '/tests/test_auth.py'));
    }

    /** `audit_tests` lists the Python findings, and counts them for Python rather than saying it has no test facts (Ruling P14). */
    public function testAuditTestsListsPythonFindings(): void
    {
        [$pdo] = $this->scanned('nemesis-experiments/module-drift');
        $projectId = (string) $pdo->query('SELECT id FROM projects')->fetchColumn();
        $audit = ArchitectureQueryService::forDatabase($pdo)->services()->testFidelityQueries->auditTests($projectId);

        assertSame(3, $audit->data['total']);
        assertSame(['tests/test_mailer.py'], array_values(array_unique(array_column($audit->data['items'], 'file'))));
        assertSame(['definite' => 1, 'warning' => 2], $audit->data['summary']['by_language']['python']);
    }

    /**
     * The frozen goldens still hold the tool runs this docblock describes;
     * `OnRequestAcceptanceTest` checks each against its fixture and commit.
     */
    public function testTheFrozenGoldensHoldWhatThisDocblockDescribes(): void
    {
        assertSame(self::MOMUS_GOLDEN, self::goldenFindings('momus', 'python'));
        assertSame(self::NEMESIS_GOLDEN, self::goldenFindings('nemesis', 'python'));
        assertSame([self::MOMUS_COMMIT, self::NEMESIS_COMMIT], [self::golden('momus', 'python')['commit'], self::golden('nemesis', 'python')['commit']]);
    }
}
