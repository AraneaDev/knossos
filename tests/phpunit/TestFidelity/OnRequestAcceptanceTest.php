<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity;

use Knossos\Git\ProcessGitWorkingTreeProvider;
use Knossos\Mcp\ToolService;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\Support\WorkerClients;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Acceptance of the on-request tier against the tools it replaces: Momus's
 * MOCK-001 and DRIFT-006, and Nemesis's stale-fixture check, as frozen in
 * `tests/Fixtures/test-fidelity/goldens/` ({@see FrozenGoldens}).
 *
 * Commands (Momus-MCP 9fe7283, Nemesis-MCP bf9be27, Nemesis 0.4.1), each on
 * a scratchpad copy:
 * - Momus over every fixture of the four language suites, with a `.momusrc`
 *   enabling the suite's language (`cache.enabled: false`):
 *   `npx tsx packages/cli/src/index.ts audit . --root <copy> --json --max-issues 1000`;
 * - Momus in diff mode over Task 2's scratch git copy of `php/momus` (committed,
 *   then `archive()` added to `src/InvoiceRepository.php` and `// reviewed`
 *   appended to `tests/InvoiceTest.php`): the same, plus `--git-diff --base HEAD`;
 * - Nemesis over each `fixtures/nemesis-*` dir, from inside the copy:
 *   `node dist/cli/main.js fixtures --strictness=all --json`, and once without
 *   `--strictness` (its default `breaking_only`) over `nemesis-data`. Its
 *   positional paths are path prefixes and no relative path starts with
 *   `./`, so `fixtures .` scans nothing: the command takes none.
 * - Nemesis `audit . --strictness=all --json` over every language fixture,
 *   frozen for the language suites.
 *
 * Templates have no Momus golden: `SynthesisGoldenTest` holds Task 6's ported
 * inline expectations instead.
 *
 * Mapping: MOCK-001 is `OVER_MOCKING/saturation`, DRIFT-006 `STALE_SINCE/stale_mock`;
 * Nemesis's fixture GHOST_METHOD (a missing field) is `FIXTURE_DRIFT/missing_required`,
 * and its fixture RETURN_DRIFT is `kind_mismatch`, `not_a_case` or
 * `unknown_key`, by message. Every on-request finding is a `warning`.
 *
 * Departures, each deliberate or a gap:
 *
 * OVER_MOCKING (Momus MOCK-001):
 * 1. Deliberate: per test file, as Momus is; the spec's "suite-wide" (§2) does not match it (Decision 3).
 * 2. Deliberate: each language's test frameworks are no dependency, Python's `unittest`, `unittest.mock`,
 *    `mock`, `pytest` and `pytest_mock` included; Momus excludes only vitest and jest. Where Momus counts
 *    `unittest` or `unittest.mock` and stays silent at 1/2, Knossos fires at 1/1: `momus-drift`
 *    `drift_test.py` and `healthy_test.py`, the tautology golden's `test_mock_only.py`, `test_zero_reach.py`
 *    and `test_zero_reach_reached.py`, and the `autospec`, `kwargs`, `patch`, `property` and `spec` experiments.
 * 3. Deliberate: a Python import inside a function, method or class is no dependency, as Momus reads only
 *    module-level imports; this keeps `test_patch_imports.py` at 1/1.
 * 4. A gap (G6-02): silent for PHP, whose worker sends no `imports` edge; `by_language` says so. Momus fires
 *    at `php/nemesis-experiments/pest` `tests/FeatureFlagTest.php:1`.
 * 5. Deliberate (preflight F1): `rust/nemesis-experiments/automock-drift` `tests/gateway_mock.rs:1` holds no
 *    double: no `#[automock]` or `mock!` makes `MockGateway`, so the file does not compile and nothing is
 *    mocked. The F1 probe is `rust/tautology` `tests/mock_echo.rs`, where both fire.
 * 6. Deliberate: a call to a spied member reads the double's value, not production. Momus counts
 *    `expect(svc.totalCents())` on a spied `svc` as a production assertion; Knossos fires on
 *    `typescript/momus` `tests/assignability.test.ts` (Momus 5 production assertions, Knossos 0) and
 *    `typescript/nemesis` `tests/UserService.spec.ts` (Momus 2, Knossos 1).
 * 7. Deliberate: a string patch target the scan resolves is mocked; Momus's parser gives those doubles no
 *    module path. Knossos fires on `inherited-collision` `tests/test_x.py` (2/2) and `patch`
 *    `tests/test_payment.py` (1/1, with item 2).
 * 8. Deliberate: a double of an outside module is mocked: `vi.spyOn(fs, …)` replaces part of `node:fs`, which
 *    Momus's spy has no target for; Knossos fires on `typescript/nemesis` `tests/packageMock.spec.ts` (1/1).
 * 9. Deliberate: Momus's `*.fixture.ts` worker fixtures are test code to Knossos (`TypeScriptAcceptanceTest`
 *    item 3), and Momus does not audit them; four of them are over-mocked.
 * 10. Deliberate: both sides count modules (preflight F2). A patch names the module declaring its target, so
 *    `patch("postal.mailer.deliver")` counts `postal/sender.py`, where `deliver` lives. An outside Python
 *    package whose project module the file imports (`from postal import mailer`, a namespace package) is
 *    that module's package, not a dependency of its own (fixed in Task 7): `module-drift` gives 2/2 where
 *    Momus gives 1/1. Each message reads `{m}/{d} dependencies mocked, {n} production assertions: over-mocked`.
 * 11. Deliberate: Momus's `mockSaturationThreshold` knob in `.momusrc` is not ported; 0.7 is a constant.
 *
 * STALE_SINCE (Momus DRIFT-006):
 * 12. Deliberate (carry-in A4): one finding per test file and target, at its first double and with the
 *    members stubbed, so no finding key repeats; Momus gives one per mock (19 over 8 files).
 * 13. A gap: `tests/AnonymousTest.php` holds an anonymous subclass of `InvoiceRepository`, which Knossos reads
 *    as no double (`PhpAcceptanceTest` item 6), so Momus's DRIFT-006 at :12 and :22 has no counterpart.
 * 14. A gap (G6-04): changed files, not hunks, as Momus. The narrowed scan-tier items stand in the files of
 *    Momus's other diff-mode issues, but `AnonymousTest.php` and `ConstructorTest.php`, whose cases Knossos
 *    has no rule for (`PhpAcceptanceTest` items 6, 7); per file they differ as that suite lists.
 *
 * FIXTURE_DRIFT (Nemesis `fixtures`):
 * 15. Deliberate (Q2(A)): always a warning; Nemesis's `missing_required`, `kind_mismatch` and `not_a_case`
 *    are definite.
 * 16. Deliberate: a finding stands at its record's line (`missing_required`) or its key's; Nemesis gives line 1.
 * 17. Deliberate: Nemesis's `breaking_only` default hides unknown keys (2 of 4 in `nemesis-data`); Knossos
 *    lists them, and `confidence` filters instead of `strictness`, which is not ported.
 * 18. Deliberate: a PHP type is named by its short name in the message and target (`Lane`, not
 *    `FixturesEnum\Lane`).
 * 19. Field facts, deliberate: PHP promoted properties are fields; a Rust `Option` (and a `Box`, `Rc` or `Arc`
 *    of one, or a bare type parameter) is not required; a pydantic `Field(...)` is required; a Python `Enum`
 *    subclass is no DTO; a Python `self.x` write is no field; PHP `type` is the resolved class name; Python
 *    `Optional[X]` without a default is required (pydantic v2); a PHP untyped property is optional; TS enum
 *    values are all or none.
 * 20. Field facts sent only where the wire shape is provable, deliberate: a TS union-resolving type sends no
 *    list, and a decorator opens it, per property and on a mapped source class; Python sends lists for
 *    dataclass, pydantic and TypedDict classes only (not NamedTuple, attrs or pydantic dataclasses), and a
 *    pydantic model passed to a call, a default naming a module constant or an alias-holding name opens it;
 *    Rust sends one only for a derived `Deserialize`; a Rust `with`/`deserialize_with` field sends no type;
 *    any serde attribute on a Rust enum, and a Python Enum hook or foreign base, drops its cases.
 * 21. Gaps in field facts: a Python subclass of a base in another file gets no list, which drops most real
 *    pydantic hierarchies; `type Maybe<T> = Option<T>` reads as required; a derive named `Deserialize` from
 *    another crate is trusted; a dataclass over a pydantic base composes the base's fields; a project on
 *    pydantic v1 would get false `missing_required` for `Optional[X]`.
 * 22. Deliberate: a type whose wire names may differ (`fields_open`: serde, pydantic or serializer renames)
 *    is never a candidate; Nemesis ignores renames.
 * 23. Deliberate: a type name two nodes hold is ambiguous and matches nothing; Nemesis keeps the last indexed.
 * 24. Deliberate: types in test code, DTOs under any `fixtures/` directory included, are never candidates.
 * 25. Deliberate checks: a map of id to record is read value by value; `Decimal` and `BigDecimal` are named
 *    types; private and protected fields are never required; constructor-assigned fields are not required;
 *    a PHP type with a non-public constructor is never judged on missing or unknown keys (the Fee probe
 *    hides 2); YAML-version-ambiguous scalars (`yes`, `1_000`, `010`, `1:30`) are never kind-judged; PHP
 *    scalars are judged with PHP's coercion and a Python `bool` fits `int`; magic members are read along the
 *    whole lineage, Python `__getattr__` included.
 * 26. Nemesis-faithful, listed for the record: top-level arrays never shape-match, and an id key colliding with
 *    a field name reads as one record.
 * 27. Deliberate: one finding per file, field, sub-rule and message; Nemesis repeats one per record.
 * 28. Readers, deliberate: a JSON duplicate key makes the file unparsable; YAML is a subset reader, and an
 *    anchor, alias, tag, merge key or second document makes the file unparsable (a gap, G6-05); a requested
 *    path is held to the root, and a link out of it is refused. Formats other than JSON and YAML are not read
 *    (a gap, G6-06).
 *
 * synthesize_mock (Momus `contract`), each deliberate unless marked:
 * 29. A symbol is resolved from the graph: an ambiguous name is an error listing each candidate, where Momus
 *    took the first match; an unknown name, a function, an enum or union, an external type, a PHP trait or
 *    final class is an error. PHP interfaces are rendered (Momus: "no class found").
 * 30. The framework comes from the input, the project's own doubles, its manifest, then the language's default;
 *    one of another language is an error (Momus rendered vitest for PHP). Not rendered (Q3(A)): faux, mry,
 *    mockers, mockiato, mocktopus, mock_derive, galvanic, and the mockito, wiremock and httpmock scaffolds.
 * 31. TypeScript: `vi.fn<(id: string) => R>()`, not Momus's removed `vi.fn<[id: string], R>()`; `jest.fn`
 *    from `@jest/globals`; a complete interface `satisfies Name`; getters, generic methods and decorated or
 *    overloaded members are omitted; an untyped return is `ReturnType<…>`; default-initialised parameters are
 *    `unknown`; literal-union aliases get a placeholder; tuples get element values and `bigint` `0n`; an
 *    ECMAScript `#private` name is no member (fixed in Task 7: it was listed as omitted).
 * 32. PHP: fully qualified names; inherited members; a `void` method stubbed without `willReturn`; `never`
 *    throws; `self`/`static` return the mock; a float is `0.0`; Mockery's `\Mockery::mock` for Pest too.
 *    A gap: docblocks are not read, so a docblock-only type is `mixed`.
 * 33. Python: `create_autospec(C, spec_set=True, instance=True)` (pytest-mock: `mocker.create_autospec`), not
 *    `patch.object` per method; an `async` method's `return_value` is the awaited result, through autospec's
 *    `AsyncMock`; dunders, static and class methods are left out, and `_x` is kept with a note; an untyped
 *    return is `Any`, where Momus infers.
 * 34. Rust: real receivers, `async`, `unsafe`, type parameters and where clauses; `mock! { pub Repo {} … }`
 *    (Momus's `pub MockRepo {}` makes `MockMockRepo`); `_`-prefixed closure parameters; an associated
 *    function through `_context`; supertraits and a struct's trait impls get their own `impl`; generic impls
 *    are omitted (incomplete), marker traits ignored, `Copy` left out with a note; a hand-written fake of a
 *    struct or generic trait is an error.
 * 35. A gap (G6-03): jest and mockall templates are compared with goldens only, never compiled; mockall's
 *    handling of associated items, `return_const`, `&[T]` as `Vec::new()` and a return borrowing a method's
 *    lifetime parameter is unverified offline.
 * 36. A gap from Decision 5's wording: `complete` says the member list is closed and nothing was omitted; an
 *    untyped parameter or return does not clear it, and is listed in its member's `unknown` instead.
 *
 * The tier:
 * 37. Deliberate (Q5(A), preflight F30): `tier` defaults to `scan`, where spec §4 reads "both tiers".
 * 38. Deliberate (G6-07): on-request findings are never stored, so they are in no snapshot, trend, turn
 *    message or gate budget; a suppression is the only way to silence one. Momus's auto-fix is not ported.
 */
#[Group('test-fidelity')]
#[Group('typescript-scanner')]
#[Group('php-scanner')]
#[Group('python-scanner')]
final class OnRequestAcceptanceTest extends KnossosTestCase
{
    use FrozenGoldens;
    use WorkerClients;

    /** Every MOCK-001 Momus gives over the language suites' fixtures, as the plan's survey listed it (preflight F26 paths). */
    private const MOMUS_MOCK_001 = [
        'php:nemesis-experiments/pest:tests/FeatureFlagTest.php',
        'python:momus-drift:test_patch_imports.py',
        'python:momus-drift:test_patch_missing.py',
        'python:momus-src-layout:tests/test_patch_existing.py',
        'python:momus-src-layout:tests/test_patch_missing.py',
        'python:nemesis-experiments/module-drift:tests/test_mailer.py',
        'python:nemesis-experiments/patch-multiple:tests/test_vault.py',
        'python:nemesis-experiments/side-effect:tests/test_mailer.py',
        'rust:nemesis-experiments/automock-drift:tests/gateway_mock.rs',
        'rust:tautology:tests/mock_echo.rs',
        'rust:tautology:tests/mock_only.rs',
        'rust:tautology:tests/zero_reach.rs',
        'typescript:nemesis/dogfood-clean:tests/catalog.test.ts',
    ];

    /** Where Knossos is silent and Momus fires: suite:fixture:file => departure. */
    private const MOMUS_ONLY = [
        'php:nemesis-experiments/pest:tests/FeatureFlagTest.php' => 4,
        'rust:nemesis-experiments/automock-drift:tests/gateway_mock.rs' => 5,
    ];

    /** Where Knossos fires and Momus is silent: suite:fixture:file => departure. */
    private const KNOSSOS_ONLY = [
        'python:momus-drift:drift_test.py' => 2,
        'python:momus-drift:healthy_test.py' => 2,
        'python:nemesis-experiments/autospec:tests/test_search.py' => 2,
        'python:nemesis-experiments/inherited-collision:tests/test_x.py' => 7,
        'python:nemesis-experiments/kwargs:tests/test_session.py' => 2,
        'python:nemesis-experiments/patch:tests/test_payment.py' => 7,
        'python:nemesis-experiments/property:tests/test_account.py' => 2,
        'python:nemesis-experiments/spec:tests/test_session.py' => 2,
        'python:tautology:tests/test_mock_only.py' => 2,
        'python:tautology:tests/test_zero_reach.py' => 2,
        'python:tautology:tests/test_zero_reach_reached.py' => 2,
        'typescript:momus:tests/assignability.test.ts' => 6,
        'typescript:momus:tests/automock.fixture.ts' => 9,
        'typescript:momus:tests/handoff.fixture.ts' => 9,
        'typescript:momus:tests/proxy.fixture.ts' => 9,
        'typescript:momus:tests/signature.fixture.ts' => 9,
        'typescript:nemesis:tests/UserService.spec.ts' => 6,
        'typescript:nemesis:tests/packageMock.spec.ts' => 8,
    ];

    /** Each FIXTURE_DRIFT's line in Knossos (departure 16): the record holding the drift. */
    private const FIXTURE_LINES = [
        'nemesis-data' => 4, 'nemesis-enum' => 4, 'nemesis-nested' => 5, 'nemesis-typed' => 4,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        if (!is_file(self::rustWorkerBinary())) {
            self::markTestSkipped('The Rust worker binary is not built.');
        }
    }

    /** @return array<string, array{string}> */
    public static function languageSuites(): array
    {
        return ['typescript' => ['typescript'], 'php' => ['php'], 'python' => ['python'], 'rust' => ['rust']];
    }

    /**
     * Over every fixture a language suite holds, Knossos's OVER_MOCKING files
     * are Momus's MOCK-001 files, less and plus the numbered departures.
     */
    #[DataProvider('languageSuites')]
    public function testOverMockingAgainstMomus(string $suite): void
    {
        $momus = [];
        $expected = [];
        $actual = [];
        foreach (self::golden('momus', $suite)['runs'] as $run) {
            $fixture = (string) $run['fixture'];
            foreach ($run['output']['result']['issues'] as $issue) {
                if ($issue['rule'] === 'MOCK-001') {
                    self::assertSame([1, 'warning'], [$issue['line'], $issue['severity']]);
                    $momus[] = $suite . ':' . $fixture . ':' . $issue['file'];
                }
            }
            /** @var array<string, string> $trees */
            $trees = $run['trees'];
            foreach ($this->onRequest($trees, 'OVER_MOCKING') as $item) {
                self::assertSame(['warning', 1, 'test.fidelity.over_mocking.v1', 'saturation', 'on_request', null], [$item['confidence'], $item['line'], $item['rule_id'], $item['sub_rule'], $item['tier'], $item['double']], $item['file']);
                $actual[] = $suite . ':' . $fixture . ':' . $item['file'];
            }
            foreach (self::KNOSSOS_ONLY as $key => $_) {
                if (str_starts_with($key, $suite . ':' . $fixture . ':')) {
                    $expected[] = $key;
                }
            }
        }
        sort($momus);
        self::assertSame(array_values(array_filter(self::MOMUS_MOCK_001, static fn(string $key): bool => str_starts_with($key, $suite . ':'))), $momus);
        $expected = [...$expected, ...array_diff($momus, array_keys(self::MOMUS_ONLY))];
        sort($expected);
        sort($actual);

        self::assertSame($expected, $actual);
    }

    /** Over each Nemesis fixture dir, Knossos's FIXTURE_DRIFT is Nemesis's `fixtures --strictness=all`, less the departures. */
    public function testFixtureDriftAgainstNemesis(): void
    {
        $runs = self::golden('nemesis', 'on-request')['runs'];
        $all = array_values(array_filter($runs, static fn(array $run): bool => str_contains((string) $run['command'], '--strictness=all')));
        self::assertCount(6, $all);
        foreach ($all as $run) {
            $dir = (string) $run['fixture'];
            $nemesis = [];
            foreach ($run['output']['violations'] as $violation) {
                $subRule = self::subRuleOf((string) $violation['message']);
                // Departure 15: Nemesis's definites; only an unknown key is a warning there.
                self::assertSame([1, $subRule === 'unknown_key' ? 'warning' : 'definite'], [$violation['line'], $violation['confidence']], $dir);
                $nemesis[] = [$subRule, $violation['file'], self::shortNames((string) $violation['target']), self::shortNames((string) $violation['message'])];
            }
            [$tools, $projectId, $root] = $this->buildToolServiceWithScan('test-fidelity/fixtures/' . $dir);
            try {
                $data = $tools->call('check_fixtures', ['project_id' => $projectId])->data;
            } finally {
                $this->removeTempTree($root);
            }
            $knossos = [];
            foreach ($data['items'] as $item) {
                self::assertSame(['FIXTURE_DRIFT', 'warning', 'on_request', self::FIXTURE_LINES[$dir] ?? null], [$item['category'], $item['confidence'], $item['tier'], $item['line']], $dir);
                $knossos[] = [$item['sub_rule'], $item['file'], $item['target'], $item['message']];
            }
            sort($nemesis);
            sort($knossos);
            self::assertSame($nemesis, $knossos, $dir);
            $summary = $run['output']['summary'];
            self::assertSame([$summary['scanned_fixtures'], $summary['unmatched_fixtures'], $summary['unparsable_fixtures'] ?? []], [$data['scanned'], count($data['unmatched']), array_column($data['unparsable'], 'file')], $dir);
        }

        // Departure 17: the default strictness hides both unknown keys of `nemesis-data`.
        $default = array_values(array_filter($runs, static fn(array $run): bool => !str_contains((string) $run['command'], '--strictness')));
        self::assertSame(['missing_required', 'missing_required'], array_map(static fn(array $violation): string => self::subRuleOf((string) $violation['message']), $default[0]['output']['violations']));
    }

    /**
     * In scope `git-diff`, STALE_SINCE gives each file Momus's DRIFT-006 does,
     * once, at its first double, but for the anonymous class (departures 12, 13).
     */
    public function testStaleSinceAgainstMomus(): void
    {
        $run = self::golden('momus', 'on-request')['runs'][0];
        $first = [];
        foreach ($run['output']['result']['issues'] as $issue) {
            if ($issue['rule'] === 'DRIFT-006') {
                self::assertSame('warning', $issue['severity']);
                $first[$issue['file']] = min($first[$issue['file']] ?? PHP_INT_MAX, (int) $issue['line']);
            }
        }
        ksort($first);
        unset($first['tests/AnonymousTest.php']);

        $repo = $this->staleRepository();
        $pdo = $this->freshTestDatabase();
        $projectId = (new ProjectScanService($pdo, self::repositoryRoot(), [$repo]))->scan($repo)->projectId;
        $queries = ArchitectureQueryService::forDatabase($pdo, gitWorkingTree: new ProcessGitWorkingTreeProvider())->services()->testFidelityQueries;
        $items = $queries->auditTests($projectId, category: 'STALE_SINCE', scope: 'git-diff', limit: 200)->data['items'];

        $knossos = [];
        foreach ($items as $item) {
            self::assertSame(['warning', 'stale_mock'], [$item['confidence'], $item['sub_rule']]);
            self::assertStringStartsWith('stale since HEAD: InvoiceRepository changed, ' . $item['file'] . ' untouched', $item['message']);
            $knossos[$item['file']] = $item['line'];
        }

        self::assertCount(count($items), $knossos);
        self::assertSame($first, $knossos);

        // The narrowed scan items stand in the files of Momus's other diff-mode issues, less the anonymous
        // class and the constructor drift Knossos has no rule for (departure 14).
        $momus = array_column(array_filter($run['output']['result']['issues'], static fn(array $issue): bool => $issue['rule'] !== 'DRIFT-006'), 'file');
        $momus = array_values(array_diff(array_unique($momus), ['tests/AnonymousTest.php', 'tests/ConstructorTest.php']));
        $narrowed = array_values(array_unique(array_column($queries->auditTests($projectId, tier: 'scan', scope: 'git-diff', limit: 200)->data['items'], 'file')));
        sort($momus);
        sort($narrowed);
        self::assertSame($momus, $narrowed);
    }

    /**
     * Each golden is what its tool printed at the recorded commit, over the
     * fixture trees as they are now, and covers every fixture of its suite.
     */
    public function testFrozenGoldensMatchTheToolRuns(): void
    {
        $files = glob(self::repositoryRoot() . '/tests/Fixtures/test-fidelity/goldens/*/*.json') ?: [];
        self::assertCount(10, $files);
        foreach ($files as $path) {
            $tool = basename(dirname($path));
            $suite = basename($path, '.json');
            $golden = self::golden($tool, $suite);
            self::assertSame([$tool, $tool === 'momus' ? self::MOMUS_COMMIT : self::NEMESIS_COMMIT], [$golden['tool'], $golden['commit']], $path);
            self::assertFileExists(__DIR__ . '/' . $golden['suite'] . '.php');
            foreach ($golden['runs'] as $run) {
                /** @var array<string, string> $trees */
                $trees = $run['trees'];
                self::assertSame(self::fixtureHash($trees), $run['fixture_sha256'], $path . ' ' . $run['fixture'] . ': the fixture changed since the tool ran on it');
                $output = $run['output'];
                $count = $tool === 'momus' ? [$output['result']['summary']['issues'], count($output['result']['issues'])] : [$output['summary']['violations_count'] ?? null, count($output['violations'])];
                self::assertSame($count[1], $count[0], $path . ' ' . $run['fixture']);
                self::assertStringNotContainsString('/tmp/', (string) json_encode($run));
            }
            if ($suite !== 'on-request') {
                $fixtures = array_column($golden['runs'], 'fixture');
                sort($fixtures);
                self::assertSame(self::suiteFixtures($suite), $fixtures, $path);
            }
        }
    }

    /** Every on-request tool answers twice alike over a non-empty answer. */
    public function testEveryOnRequestToolIsDeterministic(): void
    {
        $calls = [
            'python/momus-drift' => [
                ['audit_tests', ['tier' => 'on_request']],
                ['verify_test_doubles', ['symbol' => 'repo.Repo']],
                ['synthesize_mock', ['symbol' => 'repo.Repo']],
                ['list_test_rules', []],
            ],
            'fixtures/nemesis-data' => [['check_fixtures', []], ['audit_tests', ['tier' => 'all']]],
        ];
        foreach ($calls as $fixture => $list) {
            [$tools, $projectId, $root] = $this->buildToolServiceWithScan('test-fidelity/' . $fixture);
            try {
                foreach ($list as [$tool, $arguments]) {
                    $arguments += $tool === 'list_test_rules' ? [] : ['project_id' => $projectId];
                    $first = self::answer($tools, $tool, $arguments);
                    self::assertNotSame([], $first['items'] ?? $first['doubles'] ?? $first['members'] ?? $first['rules'] ?? [], $tool);
                    self::assertSame(json_encode($first), json_encode(self::answer($tools, $tool, $arguments)), $tool);
                }
            } finally {
                $this->removeTempTree($root);
            }
        }
    }

    /**
     * The on-request items of one category over a scanned copy of fixture
     * trees (fixture path => path in the copy).
     *
     * @param array<string, string> $trees
     * @return list<array<string, mixed>>
     */
    private function onRequest(array $trees, string $category): array
    {
        $root = self::scratchPath('on-request-acceptance');
        foreach ($trees as $from => $to) {
            $this->copyTree(self::repositoryRoot() . '/tests/Fixtures/test-fidelity/' . $from, rtrim($root . '/' . $to, '/'));
        }
        $pdo = $this->freshTestDatabase();
        $projectId = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root)->projectId;
        $audit = ArchitectureQueryService::forDatabase($pdo)->services()->testFidelityQueries->auditTests($projectId, category: $category, limit: 200);

        /** @var list<array<string, mixed>> */
        return $audit->data['items'];
    }

    /** Task 2's probe, again: `php/momus` committed, then a new method on the target and a touched test. */
    private function staleRepository(): string
    {
        if (self::locateGit() === null) {
            self::markTestSkipped('git is not available on this host.');
        }
        $repo = self::scratchPath('on-request-stale');
        $this->copyTree(self::repositoryRoot() . '/tests/Fixtures/test-fidelity/php/momus', $repo);
        $git = ['git', '-C', $repo, '-c', 'user.name=Knossos Test', '-c', 'user.email=test@example.test', '-c', 'commit.gpgsign=false'];
        $this->runFixtureCommand([...$git, 'init', '--quiet']);
        $this->runFixtureCommand([...$git, 'add', '-A']);
        $this->runFixtureCommand([...$git, 'commit', '--quiet', '-m', 'base']);
        $target = $repo . '/src/InvoiceRepository.php';
        file_put_contents($target, str_replace('    public function save(', "    public function archive(int \$id): void\n    {\n    }\n\n    public function save(", (string) file_get_contents($target)));
        file_put_contents($repo . '/tests/InvoiceTest.php', (string) file_get_contents($repo . '/tests/InvoiceTest.php') . "// reviewed\n");

        return (string) realpath($repo);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private static function answer(ToolService $tools, string $tool, array $arguments): array
    {
        /** @var array<string, mixed> */
        return $tools->call($tool, $arguments)->data;
    }

    /** Nemesis's fixture sub-rule, read from its message. */
    private static function subRuleOf(string $message): string
    {
        return match (true) {
            str_contains($message, 'is missing required field') => 'missing_required',
            str_contains($message, 'which no longer exists on') => 'unknown_key',
            str_contains($message, 'which is not a case of') => 'not_a_case',
            (bool) preg_match('/ as \w+ but \S+ declares /', $message) => 'kind_mismatch',
            default => 'unknown: ' . $message,
        };
    }

    /** A text with each PHP namespace qualifier dropped (departure 18). */
    private static function shortNames(string $text): string
    {
        return (string) preg_replace('/(?:\w+\\\\)+(\w+)/', '$1', $text);
    }

    /**
     * The fixtures a language suite runs both tools over, sorted.
     *
     * @return list<string>
     */
    private static function suiteFixtures(string $language): array
    {
        if ($language === 'typescript') {
            return ['momus', 'nemesis', 'nemesis/dogfood-clean'];
        }
        $base = self::repositoryRoot() . '/tests/Fixtures/test-fidelity/' . $language;
        $dirs = array_filter(
            array_map('basename', glob($base . '/*', GLOB_ONLYDIR) ?: []),
            static fn(string $dir): bool => (bool) preg_match('/^(momus|nemesis)(-drift|-src-layout|-magic)?$|^tautology$/', $dir),
        );
        $fixtures = [...$dirs, ...array_map(static fn(string $dir): string => 'nemesis-experiments/' . basename($dir), glob($base . '/nemesis-experiments/*', GLOB_ONLYDIR) ?: [])];
        sort($fixtures);

        return $fixtures;
    }
}
