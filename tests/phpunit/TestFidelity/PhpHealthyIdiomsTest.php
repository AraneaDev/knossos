<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity;

use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;

use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * Common healthy PHP test idioms give no definite finding, beside planted
 * twins that still do, through a real scan of `php/healthy-idioms`:
 *
 * - a counter or collector a by-reference closure writes inside a double's
 *   configuration (`willReturnCallback()`, `$this->callback()`, Mockery's
 *   `andReturnUsing()`, a Laravel configuring closure) is not a constant;
 * - a name a parent's or trait's `@method` or `__call()` answers is no ghost;
 * - a method that reads `func_get_args()`, `func_num_args()` or
 *   `func_get_arg()` in its own body takes any number of arguments;
 * - every type `Mockery::mock(A::class, B::class)` or `'A, B'` names is the
 *   double's, so a member of `B` is no ghost;
 * - a name PHPUnit's `addMethods()` adds is the double's own;
 * - Mockery's `anyArgs()`, `noArgs()`, `andAnyOtherArgs()` and
 *   `andAnyOthers()` leave the argument count open (Ruling R19);
 * - `willReturnSelf()`/`andReturnSelf()` on `void` is a warning (Ruling R18);
 * - a Stringable pin against `string` is unknown under Mockery (Ruling R20);
 * - `getMockForTrait()` makes a PHPUnit double (Ruling R16).
 */
#[Group('test-fidelity')]
#[Group('php-scanner')]
final class PhpHealthyIdiomsTest extends KnossosTestCase
{
    public function testHealthyIdiomsGiveNoFalseDefinite(): void
    {
        $root = self::scratchPath('php-healthy-idioms');
        $this->copyTree(self::repositoryRoot() . '/tests/Fixtures/test-fidelity/php/healthy-idioms', $root);
        $pdo = $this->freshTestDatabase();
        (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);
        $rows = $pdo->query(
            'SELECT f.category, f.sub_rule, f.confidence, fl.relative_path, f.start_line FROM test_findings f '
            . 'JOIN projects p ON p.id = f.project_id AND f.last_scan_id = p.active_scan_id JOIN files fl ON fl.id = f.file_id',
        )->fetchAll(PDO::FETCH_NUM);
        $findings = array_map(static fn(array $row): string => vsprintf('%s/%s/%s@%s:%d', $row), $rows);
        sort($findings);

        $expected = [
            // Genuine: the assertion reads back the stub's own value.
            'TAUTOLOGY/mock_echo/definite@tests/EdgeTest.php:25',
            'TAUTOLOGY/mock_echo/definite@tests/EdgeTest.php:53',
            // Genuine: a closure inside the method reads func_get_args(), not the method itself.
            'ARITY_MISMATCH/call_arity/definite@tests/EdgeTest.php:37',
            // Genuine: neither Primary nor Second has third().
            'GHOST_MEMBER/missing_member/definite@tests/EdgeTest.php:52',
            // Genuine: Plain is not Stringable; PHPUnit returns strictly.
            'RETURN_DRIFT/return_type/definite@tests/ExtraTest.php:19',
            'RETURN_DRIFT/return_type/definite@tests/ExtraTest.php:21',
            // Genuine: the trait double's missing() is no member of Counts; its protected step() is reachable.
            'GHOST_MEMBER/missing_member/definite@tests/ExtraTest.php:30',
            // Genuine: self() takes no argument.
            'ARITY_MISMATCH/call_arity/definite@tests/MailerTest.php:26',
            // Ruling R18: a self pin on void breaks nothing at run time.
            'RETURN_DRIFT/return_type/warning@tests/MailerTest.php:27',
            'RETURN_DRIFT/return_type/warning@tests/MailerTest.php:34',
            'TAUTOLOGY/zero_reach/warning@tests/EdgeTest.php:21',
            'TAUTOLOGY/zero_reach/warning@tests/EdgeTest.php:23',
            'TAUTOLOGY/zero_reach/warning@tests/EdgeTest.php:45',
            'TAUTOLOGY/zero_reach/warning@tests/EdgeTest.php:47',
            'TAUTOLOGY/zero_reach/warning@tests/EdgeTest.php:49',
            'TAUTOLOGY/zero_reach/warning@tests/EdgeTest.php:51',
            'TAUTOLOGY/zero_reach/warning@tests/MoreTest.php:40',
        ];
        sort($expected);

        assertSame($expected, $findings);
    }
}
