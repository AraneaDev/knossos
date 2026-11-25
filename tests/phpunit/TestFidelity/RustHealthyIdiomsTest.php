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
 * `#[cfg(test)]` alternative is production's.
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
            'TAUTOLOGY/constant/definite@tests/planted.rs:8',
            'TAUTOLOGY/self_comparison/definite@tests/planted.rs:18',
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
