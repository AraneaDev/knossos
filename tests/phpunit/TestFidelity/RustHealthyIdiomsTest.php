<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity;

use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\Support\WorkerClients;
use PDO;

use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * Common healthy Rust test idioms, through a real scan of
 * `rust/healthy-idioms`: they give no definite finding beside the planted
 * twins (Global Constraint, Decisions 7 and 8).
 *
 * `tests/idioms.rs` holds assertions that only look as if they cannot fail,
 * each operand written where a literal does not show it: `assert_eq!(x, x)`
 * on `f64::NAN` and on a type whose `PartialEq` is under test, a `let mut`
 * counter, a name shadowed at the top, in a nested block, by a closure
 * parameter and by a `match` arm, a macro handed the name, `unsafe` in the
 * function, `Cell`, `RefCell`, `Mutex` and `AtomicU32` values, a `static
 * mut`, a `const` of another file, a `const` compared with production,
 * `assert!(true)` (the R10 analog), `assert_eq!(1 + 1, 2)`, a test inside
 * `proptest! {}`, an `rstest` `#[case]` parameter compared with a literal,
 * and a `let` after the assertion. `src/lib.rs` compares a same-file
 * `const` and a literal with production, and holds what shadows a same-file
 * `const` out of a pattern's sight (review C1 and I1): a block-level `use … as`
 * and a block-level `const` of production's value, and a `const` whose
 * `#[cfg(test)]` alternative is production's, as a `const` or as a `use`
 * (re-review I1); and a same-crate `macro_rules!` invoked in the test body,
 * not handed the name, whose expansion declares it (fix round 2); a module
 * declared once per build, the test one re-exporting production's value, and
 * a project `macro_rules!` named like `std::dbg!` that declares the name
 * (fix round 3). `tests/external_macros.rs` imports another crate's macro
 * under the name `todo`, and it declares the name (fix round 4). `src/lib.rs`
 * loads `macros/limit_todo.rs` by `#[path]` from outside `src/`, whose
 * `macro_rules! todo` declares the name (fix round 5). Three member packages
 * each give a macro that may declare the name (re-review 5): `glob-macros`
 * glob-imports another crate's macro as `prop_assert`, outside the standard
 * prelude; `core-module` names one through a local `mod core`; `path-beyond`
 * loads a file by `#[path]` from outside the project.
 *
 * `src/clock.rs` mocks a trait with mockall (Task 3): an `expect_*` with
 * `.with(eq(1))`, `.times(1)` and a computed `returning`, a mock handed to
 * production, two expectations on one member, a static context, a mock behind
 * `Arc<Mutex<_>>`, an async member awaited under `#[tokio::test]`, a
 * `#[should_panic]` test with an uninvoked pinned mock, drift the compiler
 * rejects (a ghost `expect_gone()`, a wrong `.with` count, a wrong
 * `return_const` type), and a mockito server mock with no `assert()`. Its
 * `planted` module sets a `mock_echo` (definite), a `mock_only` and a
 * `zero_reach` (warnings) each beside a twin that runs production.
 *
 * Its `reviewed` module, and the members `shadow-assert` (a project
 * `macro_rules! assert_eq`) and `gated` (a `cfg_attr(test, automock)` mock
 * named from `tests/`), hold each false definite the Task 3 review found, as
 * the healthy test it is: a `macro_rules!` in the body that configures the
 * mock, and a mocked `new` whose context builds the mock. The member
 * `pretty` compares through `pretty_assertions`, still read as assertions
 * (re-review 1 ruling), with a planted `constant` beside its healthy twin.
 * The members `pretty-renamed` (a Cargo `package =` rename) and
 * `pretty-extern` (`extern crate … as pretty_assertions`) put another crate
 * under that name, whose comparisons are not read (G5-07). The member
 * `nested-manifest` holds another package's manifest in its module tree,
 * reached by plain `mod`, whose macro declares the name (G5-04).
 *
 * `src/thermometer.rs` fakes a trait by hand (Task 4): a fake handed to
 * production and a stateful fake, beside a planted `mock_echo` (definite) of
 * the fixed fake's literal. `src/store.rs` mocks a struct with faux: a closure
 * `then` and a `then_return` mock handed to production.
 *
 * `tests/planted.rs` sets each planted finding beside its healthy twin,
 * which compares with production: `assert_ne!(1, 2)` (`constant`) and
 * `assert_eq!(1, 1)` (`self_comparison`).
 */
#[Group('test-fidelity')]
final class RustHealthyIdiomsTest extends KnossosTestCase
{
    use WorkerClients;

    protected function setUp(): void
    {
        parent::setUp();
        if (!is_file(self::rustWorkerBinary())) {
            self::markTestSkipped('The Rust worker binary is not built.');
        }
    }

    public function testHealthyIdiomsGiveNoFalseDefinite(): void
    {
        $pdo = $this->scan();
        $rows = $pdo->query(
            'SELECT f.category, f.sub_rule, f.confidence, fl.relative_path, f.start_line FROM test_findings f '
            . 'JOIN projects p ON p.id = f.project_id AND f.last_scan_id = p.active_scan_id JOIN files fl ON fl.id = f.file_id',
        )->fetchAll(PDO::FETCH_NUM);
        $findings = array_map(static fn(array $row): string => vsprintf('%s/%s/%s@%s:%d', $row), $rows);
        sort($findings);
        $cases = (int) $pdo->query("SELECT COUNT(*) FROM nodes WHERE language = 'rust' AND kind = 'test_case'")->fetchColumn();

        // Every idiom is a case the rules judge, so the list asserts something.
        assertSame(true, $cases > 20);
        assertSame([
            // Genuine, each the planted twin of the healthy idiom beside it.
            // `pretty_assertions`' `assert_ne` compares as std's does.
            'TAUTOLOGY/constant/definite@pretty/src/lib.rs:23',
            'TAUTOLOGY/constant/definite@tests/planted.rs:8',
            'TAUTOLOGY/mock_echo/definite@src/clock.rs:160',
            // A hand-written fake's pinned value compared with itself.
            'TAUTOLOGY/mock_echo/definite@src/thermometer.rs:57',
            'TAUTOLOGY/mock_only/warning@src/clock.rs:174',
            // A `.times(0)` with no production run: asserted, so no
            // zero_reach (R5-7), and only a double is asserted.
            'TAUTOLOGY/mock_only/warning@src/clock.rs:244',
            'TAUTOLOGY/self_comparison/definite@tests/planted.rs:18',
            'TAUTOLOGY/zero_reach/warning@src/clock.rs:186',
        ], $findings);
    }

    /** Scan a fresh copy of the fixture. */
    private function scan(): PDO
    {
        $root = self::scratchPath('rust-healthy-idioms');
        $this->copyTree(self::repositoryRoot() . '/tests/Fixtures/test-fidelity/rust/healthy-idioms', $root);
        $pdo = $this->freshTestDatabase();
        (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);

        return $pdo;
    }
}
