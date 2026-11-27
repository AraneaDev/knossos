<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity;

use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;

use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * Common healthy TypeScript test idioms give no false finding, beside planted
 * twins that still report, through a real scan of `typescript/healthy-idioms`:
 *
 * - a stub's implementation pins a value only when every `return` of its own
 *   body hands back the same literal; a `return` inside a nested function is
 *   that function's (G1-09);
 * - `it.fails` passes only when an assertion fails, so none of its assertions
 *   is a tautology, though `it.concurrent`, `it.skipIf()` and both tests of one
 *   line are read as tests (G1-10);
 * - `expect(true).not.toBe(true)` cannot pass, and says so (G1-17);
 * - `expect(spy).not.toHaveBeenCalledWith()` for a member that needs an
 *   argument cannot fail: a vacuous assertion, not arity drift, though the
 *   same matcher without `.not` is drift; `.rejects` is read (G3-02);
 * - a method named by the string `'#x'` is public, though a `#hidden` member
 *   is no property a double can replace (G1-27);
 * - `tests/db.test.ts` is named for `src/db.ts` as much as for
 *   `src/legacy/db.ts`, so mocking the legacy one is mocking a dependency,
 *   though `tests/legacy/db.test.ts` is named for it (G1-19).
 */
#[Group('test-fidelity')]
#[Group('typescript-scanner')]
final class TypeScriptHealthyIdiomsTest extends KnossosTestCase
{
    public function testHealthyIdiomsGiveNoFalseFinding(): void
    {
        $root = self::scratchPath('ts-healthy-idioms');
        $this->copyTree(self::repositoryRoot() . '/tests/Fixtures/test-fidelity/typescript/healthy-idioms', $root);
        $pdo = $this->freshTestDatabase();
        (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);
        $rows = $pdo->query(
            'SELECT f.category, f.sub_rule, f.confidence, fl.relative_path, f.start_line, f.message FROM test_findings f '
            . 'JOIN projects p ON p.id = f.project_id AND f.last_scan_id = p.active_scan_id JOIN files fl ON fl.id = f.file_id',
        )->fetchAll(PDO::FETCH_NUM);
        $findings = array_map(static fn(array $row): string => vsprintf('%s/%s/%s@%s:%d %s', $row), $rows);

        assertSame(self::sorted([
            // Genuine (G1-10, G1-17): a constant under `.not` cannot pass; a concurrent test and both tests of line 14 are read.
            'TAUTOLOGY/constant/definite@tests/calc.test.ts:10 true is compared with true, both constants; the assertion cannot pass',
            'TAUTOLOGY/self_comparison/definite@tests/calc.test.ts:13 1 is compared with itself; the assertion cannot fail',
            'TAUTOLOGY/self_comparison/definite@tests/calc.test.ts:14 2 is compared with itself; the assertion cannot fail',
            'TAUTOLOGY/self_comparison/definite@tests/calc.test.ts:14 3 is compared with itself; the assertion cannot fail',
            // Genuine (G1-09): every return of the stub is 4.
            'TAUTOLOGY/mock_echo/definite@tests/echo.test.ts:20 the assertion compares the stubbed value 4 with itself; it cannot fail',
            // Genuine (G1-19): the test's path names the legacy module it mocks.
            "MOCKS_SUBJECT/mock_of_self/warning@tests/legacy/db.test.ts:6 vi.mock ../../src/legacy/db replaces src/legacy/db.ts, which the test 'queries the legacy db' exercises",
            // Vacuous (G3-02), then the genuine drift of the same matcher without `.not`.
            'TAUTOLOGY/vacuous_negation/warning@tests/not.test.ts:9 rate needs 1 argument(s); the negated toHaveBeenCalledWith expects 0, so it cannot fail',
            'ARITY_MISMATCH/call_arity/definite@tests/not.test.ts:15 rate needs 1 argument(s); the matcher expects 0',
            // Genuine (G1-27): a real ECMAScript private member.
            'VISIBILITY_BREACH/visibility/definite@tests/odd.test.ts:14 #hidden is private; a double cannot replace it',
        ]), self::sorted($findings));
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
}
