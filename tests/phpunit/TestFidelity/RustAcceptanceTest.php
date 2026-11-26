<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\Support\WorkerClients;
use PDO;

use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Acceptance against the tools test fidelity replaces, for Rust: a real scan
 * of the Momus and Nemesis Rust fixtures stores what Knossos makes of what
 * those tools report on them, mapped onto Knossos's categories as
 * `category/sub_rule/confidence@file:line`, beside a tautology golden of
 * Knossos's own.
 *
 * The lists come from running both tools on copies of the fixtures
 * (Momus-MCP 9fe7283, Nemesis-MCP bf9be27, its `dist/` rebuilt from that
 * commit):
 *
 * - Momus over `momus-drift/` (its `packages/parser-rust/test/fixtures/drift`)
 *   and over each Nemesis copy, with a `.momusrc` of
 *   `{"languages":{"rust":true,"typescript":false,"php":false,"python":false},"cache":{"enabled":false}}`
 *   in the copy: `npx tsx packages/cli/src/index.ts audit --root <copy> --max-issues 1000`.
 *   Over `momus-drift/` it gives exactly its own golden, "rust drift
 *   fixtures" in `test/golden/audit.test.ts`: DRIFT-001 at
 *   `drift_test.rs:10`; DRIFT-003 at `drift_test.rs:11`, `:12` and
 *   `mocktopus_test.rs:12`; MOCK-002 at `generic_test.rs:36` and `:43`;
 *   TAUT-005 at `drift_test.rs:9`, `healthy_test.rs:9` and
 *   `mocktopus_test.rs:12`. Over `automock-drift` it gives DRIFT-001 at
 *   `tests/gateway_mock.rs:7`, MOCK-001 at `:1` and TAUT-005 at `:5` and
 *   `:12`; over the other Nemesis copies, nothing.
 * - Nemesis over each Nemesis fixture (`nemesis/` is `fixtures/rust`,
 *   `nemesis-experiments/<name>/` is `fixtures/experiments/rust/<name>`) and
 *   over `momus-drift/`, run from inside the copy:
 *   `node dist/cli/main.js audit . --strictness=all`. It gives, all
 *   definite: GHOST_METHOD at `drift_test.rs:10`,
 *   `tests/gateway_mock.rs:7` and `tests/store_mock.rs:4`; RETURN_DRIFT at
 *   `drift_test.rs:11` and `:12`; ARITY_MISMATCH at
 *   `tests/gateway_mock.rs:13`.
 * - Both tools were also run over `tautology/`. Momus gives TAUT-001 and
 *   TAUT-003 at `tests/self_comparison.rs:5`, TAUT-003 at
 *   `tests/constant.rs:5`, TAUT-005 at `tests/zero_reach.rs:5`, and MOCK-001
 *   at line 1 of `mock_echo.rs`, `mock_only.rs` and `zero_reach.rs`;
 *   Nemesis gives nothing.
 *
 * The tool runs agreed with the plan's dry run in every finding; only the
 * golden's own anchors were settled by the run (a `zero_reach` stands at its
 * double, `tests/zero_reach.rs:5`).
 *
 * DRIFT-001 and GHOST_METHOD are `GHOST_MEMBER/missing_member`, DRIFT-003
 * and RETURN_DRIFT `RETURN_DRIFT/return_type`, ARITY_MISMATCH
 * `ARITY_MISMATCH/call_arity`, TAUT-001 `TAUTOLOGY/self_comparison`,
 * TAUT-002 `mock_echo`, TAUT-003 `constant`, TAUT-004 `mock_only`, TAUT-005
 * `zero_reach` and MOCK-002 `MOCKS_SUBJECT`.
 *
 * Departures, where Knossos deliberately differs, and gaps, where a tool
 * reports a case Knossos has no rule for yet (items 6 and 7):
 *
 * 1. Compile-checked, so silent (Decision 1): DRIFT-001 and GHOST_METHOD at
 *    `drift_test.rs:10` (where `MockRepo` has no generator either) and
 *    `tests/gateway_mock.rs:7`. A mockall `expect_*` of a member the trait
 *    lacks does not compile, so the drift never reaches a test run.
 * 2. Compile-checked, so silent (Decision 1): DRIFT-003 and RETURN_DRIFT at
 *    `drift_test.rs:11` and `:12`, and DRIFT-003 at `mocktopus_test.rs:12`.
 *    A `return_const` or `MockResult::Return` value of the wrong type does
 *    not compile.
 * 3. Compile-checked, so silent (Decision 1): ARITY_MISMATCH at
 *    `tests/gateway_mock.rs:13`; a `.with` of the wrong count does not
 *    compile.
 * 4. No generator, so no double: TAUT-005 at `drift_test.rs:9`,
 *    `healthy_test.rs:9`, and `tests/gateway_mock.rs:5` and `:12`. No
 *    `#[automock]` or `mock!` makes `MockRepo` (`repo.rs` declares a plain
 *    trait) or `MockGateway`, which is never imported, so Knossos sees no
 *    double to judge. The code does not compile, so the stub never runs.
 * 5. One finding per assertion: at `tautology` `tests/self_comparison.rs:5`
 *    Momus gives TAUT-001 and TAUT-003 on `assert_eq!(7, 7)`; Knossos gives
 *    `self_comparison` alone, as in every language.
 * 6. Deferred (G5-01, user decision Q2): GHOST_METHOD at
 *    `tests/store_mock.rs:4`, a `mock!` namesake whose `Store` lost
 *    `purge`. The `mock!` block declares the member itself, so it compiles;
 *    judging it needs a complete member list for a Rust type. `Store` does
 *    not resolve in the test file either.
 * 7. A gap (G5-02): MOCK-002 (info) at `generic_test.rs:36` and `:43`.
 *    `MOCKS_SUBJECT` is silent for Rust (Decision 10).
 * 8. On request in phase 6: Momus's MOCK-001 (`automock-drift`
 *    `tests/gateway_mock.rs:1`, and the `tautology` mock files) is an
 *    on-request check in Knossos, not a scan one.
 * 9. Agreed: Momus's TAUT-005 at `mocktopus_test.rs:12` is
 *    `TAUTOLOGY/zero_reach/warning`: a mocktopus pin nothing calls.
 * 10. Knossos-only, each checked true by reading the source:
 *    `mock_echo/definite` at `generic_test.rs:38` (`return_const(42u32)`,
 *    then `assert_eq!(42u32, mock.myfunc())`), `tests/clock_mock.rs:12`
 *    (`returning(|| 1)`, then `assert_eq!(clock.now(), 1)`) and
 *    `tests/storage_mock.rs:18` (`returning(|_, _| 1)`, then
 *    `assert_eq!(mock.put("k", b"v"), 1)`); `mock_only/warning` at
 *    `tests/repository_mock.rs:13`, whose assertion names only the mock and
 *    whose test runs no production. Momus is silent on each, and Nemesis
 *    has no tautology rules.
 * 11. Knossos-only in the golden: `mock_echo` at `tests/mock_echo.rs:7` and
 *    `mock_only` at `tests/mock_only.rs:7`, where Momus is silent. The
 *    mock is the crate's own `tautology::clock::MockClock`, imported.
 *
 * The tautology golden (`tautology/`) sets one planted case beside its
 * healthy twin, which runs production, for each category that applies to
 * Rust: `self_comparison` (two equal literals), `constant`, `mock_echo`,
 * `mock_only` and `zero_reach`. `unconfigured_spy` does not apply (Decision
 * 10): no Rust framework wraps a real object. Every test of it compiles and
 * passes under `cargo test` (rustc 1.99.1, mockall 0.13.1), as only an
 * assertion that cannot fail does.
 */
#[Group('test-fidelity')]
final class RustAcceptanceTest extends KnossosTestCase
{
    use WorkerClients;

    private const FIXTURES = '/tests/Fixtures/test-fidelity/rust';

    protected function setUp(): void
    {
        parent::setUp();
        if (!is_file(self::rustWorkerBinary())) {
            self::markTestSkipped('The Rust worker binary is not built.');
        }
    }

    /**
     * A project made of a copy of one fixture directory, scanned once.
     *
     * @return array{0: PDO, 1: ProjectScanService, 2: string}
     */
    private function scanned(string $fixture): array
    {
        $root = self::scratchPath('rust-acceptance');
        $this->copyTree(self::repositoryRoot() . self::FIXTURES . '/' . $fixture, $root);

        return $this->scannedAt($root);
    }

    /** @return array{0: PDO, 1: ProjectScanService, 2: string} */
    private function scannedAt(string $root): array
    {
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

    public function testMomusRustFixtures(): void
    {
        [$pdo] = $this->scanned('momus-drift');

        assertSame(self::sorted([
            'TAUTOLOGY/mock_echo/definite@generic_test.rs:38',
            'TAUTOLOGY/zero_reach/warning@mocktopus_test.rs:12',
        ]), self::findings($pdo));
    }

    public function testNemesisRustFixtures(): void
    {
        [$pdo] = $this->scanned('nemesis');

        assertSame(['TAUTOLOGY/mock_echo/definite@tests/storage_mock.rs:18'], self::findings($pdo));
    }

    /** @return array<string, array{string, list<string>}> */
    public static function experiments(): array
    {
        return [
            'automock' => ['automock', ['TAUTOLOGY/mock_echo/definite@tests/clock_mock.rs:12']],
            'automock-drift' => ['automock-drift', []],
            'mock-block' => ['mock-block', []],
            'mock-macro' => ['mock-macro', []],
            'trait-shapes' => ['trait-shapes', ['TAUTOLOGY/mock_only/warning@tests/repository_mock.rs:13']],
        ];
    }

    /** @param list<string> $expected */
    #[DataProvider('experiments')]
    public function testNemesisExperiments(string $experiment, array $expected): void
    {
        [$pdo] = $this->scanned('nemesis-experiments/' . $experiment);

        assertSame(self::sorted($expected), self::findings($pdo));
    }

    /** One planted case of each tautology that applies to Rust beside its healthy twin, which is silent. */
    public function testKnossosTautologyGolden(): void
    {
        [$pdo] = $this->scanned('tautology');

        assertSame(self::sorted([
            'TAUTOLOGY/constant/definite@tests/constant.rs:5',
            'TAUTOLOGY/mock_echo/definite@tests/mock_echo.rs:7',
            'TAUTOLOGY/mock_only/warning@tests/mock_only.rs:7',
            'TAUTOLOGY/self_comparison/definite@tests/self_comparison.rs:5',
            'TAUTOLOGY/zero_reach/warning@tests/zero_reach.rs:5',
        ]), self::findings($pdo));
    }

    public function testFindingsAreDeterministic(): void
    {
        $ids = static fn(PDO $pdo): array => $pdo->query('SELECT id FROM test_findings ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        [$first, , $root] = $this->scanned('tautology');
        [$second] = $this->scannedAt($root);

        assertNotSame([], self::findings($first));
        assertSame($ids($first), $ids($second));
        assertSame(self::findings($first), self::findings($second));
    }

    /**
     * Removing `#[automock]` from the trait in `src/clock.rs` clears the echo
     * in `src/service.rs`, a file the edit never touches, through the core's
     * incremental path; restoring it brings the echo back.
     */
    public function testRemovingAutomockFromTheTraitClearsTheEchoInAnUntouchedFile(): void
    {
        $root = self::scratchPath('rust-acceptance-automock');
        $clock = "use mockall::automock;\n\n#[automock]\npub trait Clock {\n    fn now(&self) -> u64;\n}\n";
        $service = "use crate::clock::Clock;\n\npub fn elapsed(clock: &dyn Clock, start: u64) -> u64 {\n    clock.now() - start\n}\n\n"
            . "#[cfg(test)]\nmod tests {\n    use crate::clock::{Clock, MockClock};\n\n"
            . "    #[test]\n    fn echo() {\n        let mut clock = MockClock::new();\n        clock.expect_now().return_const(5u64);\n        assert_eq!(clock.now(), 5);\n    }\n\n"
            . "    #[test]\n    fn constant() {\n        assert_ne!(1, 2);\n    }\n}\n";
        $other = "#[cfg(test)]\nmod tests {\n    #[test]\n    fn constant() {\n        assert_ne!(3, 4);\n    }\n}\n";
        $this->writeTree($root, [
            'Cargo.toml' => "[package]\nname = \"rename\"\nversion = \"0.1.0\"\nedition = \"2021\"\n\n[dependencies]\nmockall = \"0.13\"\n",
            'src/lib.rs' => "pub mod clock;\npub mod other;\npub mod service;\n",
            'src/clock.rs' => $clock,
            'src/service.rs' => $service,
            'src/other.rs' => $other,
        ]);
        [$pdo, $scan] = $this->scannedAt($root);
        $before = self::findings($pdo);
        assertSame([2, 1], self::perFile($pdo));
        assertContains('TAUTOLOGY/mock_echo/definite@src/service.rs:15', $before);

        file_put_contents($root . '/src/clock.rs', str_replace("#[automock]\n", '', $clock));
        $removed = $scan->scan($root);

        assertSame([1, 1], self::perFile($pdo));
        assertSame(self::sorted(array_values(array_diff($before, ['TAUTOLOGY/mock_echo/definite@src/service.rs:15']))), self::findings($pdo));
        // The edited file is parsed again, and so are both test files: `service.rs` names its generated mock,
        // and each reads every file of the package to know that no `macro_rules!` shadows `assert_eq!` or
        // `assert_ne!`. Only `lib.rs` is reused.
        assertSame([3, 1], [$removed->data['parsed_files'], $removed->data['unchanged_files']]);

        file_put_contents($root . '/src/clock.rs', $clock);
        $scan->scan($root);

        assertSame([2, 1], self::perFile($pdo));
        assertSame($before, self::findings($pdo));
        assertSame($service, (string) file_get_contents($root . '/src/service.rs'));
    }

    /**
     * Adding a blanket trait to `src/ext.rs`, which the test module of `src/service.rs` glob-imports, clears
     * the echo there: the trait's by-value `now(self)` may take `clock.now()` before `Clock::now(&self)`.
     * The edit never touches `service.rs`; removing the trait brings the echo back.
     */
    public function testAddingABlanketTraitToAGlobbedFileClearsTheEchoInAnUntouchedFile(): void
    {
        $root = self::scratchPath('rust-acceptance-trait-rival');
        $clock = "use mockall::automock;\n\n#[automock]\npub trait Clock {\n    fn now(&self) -> u64;\n}\n";
        $ext = "pub fn helper() -> u64 {\n    1\n}\n";
        $rival = "pub trait Later: crate::clock::Clock + Sized {\n    fn now(self) -> u64 {\n        0\n    }\n}\n\n"
            . "impl<T: crate::clock::Clock> Later for T {}\n";
        $service = "#[cfg(test)]\nmod tests {\n    use crate::clock::{Clock, MockClock};\n    use crate::ext::*;\n\n"
            . "    #[test]\n    fn echo() {\n        let mut clock = MockClock::new();\n        clock.expect_now().return_const(5u64);\n"
            . "        assert_eq!(clock.now(), 5);\n    }\n}\n";
        $this->writeTree($root, [
            'Cargo.toml' => "[package]\nname = \"rival\"\nversion = \"0.1.0\"\nedition = \"2021\"\n\n[dependencies]\nmockall = \"0.13\"\n",
            'src/lib.rs' => "pub mod clock;\npub mod ext;\npub mod service;\n",
            'src/clock.rs' => $clock,
            'src/ext.rs' => $ext,
            'src/service.rs' => $service,
        ]);
        [$pdo, $scan] = $this->scannedAt($root);
        $echo = 'TAUTOLOGY/mock_echo/definite@src/service.rs:10';
        assertSame([$echo], self::findings($pdo));

        file_put_contents($root . '/src/ext.rs', $ext . $rival);
        $added = $scan->scan($root);

        assertSame([], self::findings($pdo));
        // The edited file and `service.rs`, which read it through its glob, are parsed again.
        assertSame(2, $added->data['parsed_files']);

        file_put_contents($root . '/src/ext.rs', $ext);
        $scan->scan($root);

        assertSame([$echo], self::findings($pdo));
        assertSame($service, (string) file_get_contents($root . '/src/service.rs'));
    }

    /** `audit_tests` counts the Rust findings for Rust rather than saying it has no test facts. */
    public function testAuditTestsCountsRust(): void
    {
        [$pdo] = $this->scanned('tautology');
        $projectId = (string) $pdo->query('SELECT id FROM projects')->fetchColumn();
        $audit = ArchitectureQueryService::forDatabase($pdo)->services()->testFidelityQueries->auditTests($projectId);

        assertSame(5, $audit->data['total']);
        assertSame(['definite' => 3, 'warning' => 2], $audit->data['summary']['by_language']['rust']);
    }

    /**
     * How many findings `src/service.rs` and `src/other.rs` hold now.
     *
     * @return array{int, int}
     *
     * @phpstan-impure
     */
    private static function perFile(PDO $pdo): array
    {
        $in = static fn(string $file): int => count(array_filter(
            self::findings($pdo),
            static fn(string $finding): bool => str_contains($finding, '@' . $file . ':'),
        ));

        return [$in('src/service.rs'), $in('src/other.rs')];
    }

    /** @param array<string, string> $files */
    private function writeTree(string $root, array $files): void
    {
        foreach ($files as $path => $contents) {
            $file = $root . '/' . $path;
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0o777, true);
            }
            file_put_contents($file, $contents);
        }
    }
}
