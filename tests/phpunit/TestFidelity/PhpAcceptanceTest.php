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
 * Acceptance against the tools test fidelity replaces, for PHP: a real scan
 * of the Momus and Nemesis PHP fixtures stores what those tools report on
 * them, mapped onto Knossos's categories as `category/sub_rule/confidence@file:line`.
 *
 * Momus has no PHP golden list, so the lists come from running both tools on
 * copies of the fixtures (Momus-MCP 9fe7283, Nemesis-MCP bf9be27):
 *
 * - Momus over `momus/` (its `packages/parser-php/test/fixtures`), with a
 *   `.momusrc` of `{"languages":{"php":true},"cache":{"enabled":false}}` in
 *   the copy, since Momus audits PHP only when told to:
 *   `npx tsx packages/cli/src/index.ts audit --root <copy> --max-issues 1000`.
 * - Nemesis over each Nemesis fixture (`nemesis/` is `fixtures/php`,
 *   `nemesis-magic/` is `fixtures/magic-php` without its `fixtures/*.json`,
 *   `nemesis-experiments/<name>/` is `fixtures/experiments/php/<name>`), run
 *   from inside the copy, as its CLI resolves paths under the working
 *   directory: `node dist/cli/main.js audit . --strictness=all`.
 * - Each tool was also run over the other's fixtures, to find where they disagree.
 *
 * Momus's DRIFT-001 is `GHOST_MEMBER/missing_member`, DRIFT-003
 * `RETURN_DRIFT/return_type`, TAUT-001 and TAUT-003 `TAUTOLOGY/self_comparison`,
 * TAUT-002 `mock_echo`, TAUT-004 `mock_only` and TAUT-005 `zero_reach`;
 * Nemesis's GHOST_METHOD is `GHOST_MEMBER/missing_member`, and an
 * ARITY_MISMATCH is `call_arity` for a `with()` and `signature` for a callback.
 *
 * Disagreements, and where Knossos deliberately differs from both:
 *
 * 1. `momus` `Drift003Test.php:14`, `:21`, `:28`, `PartialMockTest.php:13`,
 *    `:19`: Momus warns, Nemesis reports definite drift; both sides carry
 *    declared types, so Nemesis's `definite` is expected.
 * 2. `momus` `InvoiceTest.php:22`, `SetUpMockTest.php:25`: Momus warns; Nemesis
 *    does not resolve these doubles (an aliased import, a `setUp()` property).
 *    An `int` pinned on a method returning `Invoice` is definite, as Nemesis
 *    judges the same stub at `Drift003Test.php:21`. Where Nemesis resolves no
 *    double it reports no outcome, so Momus's findings stand there, as the
 *    ghost at `MockeryPestTest.php:33` (a string target Nemesis does not see).
 * 3. `momus` `InvoiceTest.php:16`: Momus reports TAUT-001 and TAUT-003 on one
 *    assertion; Knossos gives one finding, `self_comparison`.
 * 4. `momus` `SetUpMockTest.php:26`: Momus reports a mock echo through a
 *    `setUp()` property. A double held in a `$this` property never answers
 *    with its pin (Ruling R14), so there is none.
 * 5. `momus` `DocblockTest.php:14`, `:21`: Momus warns from `@return`
 *    docblocks; Nemesis warns at `:14`, `:21`, `:28`, `:29` and `:30` that no
 *    return type is declared, so the contract cannot be verified. Knossos holds a pin
 *    against the native return type only, and says nothing where there is
 *    none, on the healthy twin (`:28`-`:30`) as on the planted stubs.
 * 6. `momus` `AnonymousTest.php:13`: Momus reports an anonymous subclass of
 *    `InvoiceRepository` adding a method; Nemesis does not see the construct.
 *    Knossos reads no anonymous class as a double: silent.
 * 7. `momus` `ConstructorTest.php:13`: Momus reports constructor drift on
 *    `getMockBuilder()->enableOriginalConstructor()`; Nemesis does not see it.
 *    Knossos has no constructor-drift rule yet: silent.
 * 8. `momus` `BrokenTest.php:10`: Nemesis reports drift in a file that does
 *    not parse; Momus and Knossos read no facts from it.
 * 9. `momus` `InvoiceTest.php:14`, `:29`, `Drift003Test.php:35`,
 *    `MockeryPestTest.php:20`, `:26`: warnings neither tool gives. These files
 *    do not import `App\Invoice`, so `new Invoice()` in namespace `App\Tests`
 *    is `App\Tests\Invoice`, as PHP resolves it. The scan holds no such class,
 *    so the fit is in doubt, a warning (those tests would fail on a missing class).
 * 10. `momus` `ReassignTest.php:14`, `SetUpMockTest.php:31`: definite drift
 *    neither tool gives, `willReturn(null)` on a `void` method. Both tools
 *    take it for healthy, but PHPUnit refuses it ("Method save may not return
 *    value of type null, its declared return type is "void"", checked on
 *    PHPUnit 12.5), so the strict PHPUnit judgement holds (Ruling R17).
 * 11. `mock_only` warnings neither tool gives: `momus` `EdgeCasesTest.php:33`,
 *    `nemesis-magic` `BagTest.php:17`, and every experiment's
 *    `assertNotNull($double)` or `expect($double)->not->toBeNull()`. Each
 *    asserts on a double alone in a test that runs no production code, which
 *    is TAUT-004; Momus reads only `assertSame()`, `assertEquals()` and
 *    `assertNotSame()` as PHP assertions, and Nemesis has no tautology rules.
 *    Momus reports MOCK-002 (info) on the experiments' tests instead.
 * 12. `nemesis` `BillingServiceTest.php:20`, `:27`, `:34`: Momus's zero-reach
 *    warnings are expected, as Nemesis has no tautology rules. The
 *    `assertTrue(true)` beside them is silent, as in both tools (Ruling R10).
 * 13. `nemesis-magic` `BagTest.php:15`: Momus reports a ghost, Nemesis nothing;
 *    `Bag` declares `__call()`, so Nemesis's silence is expected.
 * 14. `nemesis-experiments/final`: Nemesis reports a final class (`:14`), a
 *    final method (`:17`) and a constructor (`:20`) as definite breaches, a
 *    static method (`:18`) as a warning, and warns at `:20` that the
 *    constructor declares no return type. Knossos does not yet judge whether a
 *    class or member can be doubled at all, and is silent where no return
 *    type is declared. At `:18` it reports definite drift instead,
 *    `willReturn(null)` for `build(): self`, which PHPUnit 12.5 refuses
 *    ("may not return value of type null"). Momus's zero-reach at `:16` is expected.
 * 15. `nemesis-experiments/fluent` `QueryTest.php:17`: Nemesis reports
 *    `Order::Random`, a case the enum lacks. Knossos reads no enum cases yet,
 *    so the pin's type is unknown: silent.
 * 16. Momus's MOCK-001 (over-mocking, `pest` `FeatureFlagTest.php:1`) is an
 *    on-request check, not a scan one; MOCK-002 (info) is not
 *    `MOCKS_SUBJECT` here, since these tests run no production code.
 */
#[Group('test-fidelity')]
#[Group('php-scanner')]
final class PhpAcceptanceTest extends KnossosTestCase
{
    private const FIXTURES = '/tests/Fixtures/test-fidelity/php';

    /**
     * A project made of a copy of one fixture directory, scanned once.
     *
     * @return array{0: PDO, 1: ProjectScanService, 2: string}
     */
    private function scanned(string $fixture): array
    {
        $root = self::scratchPath('php-acceptance');
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

    public function testMomusPhpFixtures(): void
    {
        [$pdo] = $this->scanned('momus');

        assertSame(self::sorted([
            'GHOST_MEMBER/missing_member/definite@tests/AbstractMockTest.php:13',
            'RETURN_DRIFT/return_type/definite@tests/Drift003Test.php:14',
            'RETURN_DRIFT/return_type/definite@tests/Drift003Test.php:21',
            'RETURN_DRIFT/return_type/definite@tests/Drift003Test.php:28',
            'RETURN_DRIFT/return_type/warning@tests/Drift003Test.php:35',
            'TAUTOLOGY/zero_reach/warning@tests/Drift003Test.php:36',
            'TAUTOLOGY/mock_only/warning@tests/EdgeCasesTest.php:33',
            'TAUTOLOGY/zero_reach/warning@tests/InvoiceTest.php:13',
            'RETURN_DRIFT/return_type/warning@tests/InvoiceTest.php:14',
            'GHOST_MEMBER/missing_member/definite@tests/InvoiceTest.php:15',
            'TAUTOLOGY/self_comparison/definite@tests/InvoiceTest.php:16',
            'RETURN_DRIFT/return_type/definite@tests/InvoiceTest.php:22',
            'TAUTOLOGY/mock_echo/definite@tests/InvoiceTest.php:23',
            'RETURN_DRIFT/return_type/warning@tests/InvoiceTest.php:29',
            'GHOST_MEMBER/missing_member/definite@tests/MockeryPestTest.php:13',
            'RETURN_DRIFT/return_type/warning@tests/MockeryPestTest.php:20',
            'RETURN_DRIFT/return_type/warning@tests/MockeryPestTest.php:26',
            'GHOST_MEMBER/missing_member/definite@tests/MockeryPestTest.php:33',
            'TAUTOLOGY/zero_reach/warning@tests/MockeryPestTest.php:41',
            'GHOST_MEMBER/missing_member/definite@tests/MockeryPestTest.php:42',
            'RETURN_DRIFT/return_type/definite@tests/PartialMockTest.php:13',
            'RETURN_DRIFT/return_type/definite@tests/PartialMockTest.php:19',
            'TAUTOLOGY/zero_reach/warning@tests/ReassignTest.php:13',
            'RETURN_DRIFT/return_type/definite@tests/ReassignTest.php:14',
            'GHOST_MEMBER/missing_member/definite@tests/SetUpMockTest.php:19',
            'RETURN_DRIFT/return_type/definite@tests/SetUpMockTest.php:25',
            'RETURN_DRIFT/return_type/definite@tests/SetUpMockTest.php:31',
        ]), self::findings($pdo));
    }

    public function testNemesisPhpFixtures(): void
    {
        [$pdo] = $this->scanned('nemesis');

        assertSame(self::sorted([
            'GHOST_MEMBER/missing_member/definite@tests/BillingServiceTest.php:14',
            'TAUTOLOGY/zero_reach/warning@tests/BillingServiceTest.php:20',
            'ARITY_MISMATCH/call_arity/definite@tests/BillingServiceTest.php:21',
            'TAUTOLOGY/zero_reach/warning@tests/BillingServiceTest.php:27',
            'RETURN_DRIFT/return_type/definite@tests/BillingServiceTest.php:28',
            'TAUTOLOGY/zero_reach/warning@tests/BillingServiceTest.php:34',
        ]), self::findings($pdo));
    }

    /** A name stubbed on a class that answers `__call()` is no ghost; the one finding is about the assertion, not the double's contract. */
    public function testNemesisMagicIsSilent(): void
    {
        [$pdo] = $this->scanned('nemesis-magic');
        $findings = self::findings($pdo);

        assertSame([], array_values(array_filter($findings, static fn(string $finding): bool => !str_starts_with($finding, 'TAUTOLOGY/'))));
        assertSame(['TAUTOLOGY/mock_only/warning@tests/BagTest.php:17'], $findings);
    }

    /** @return array<string, array{string, list<string>}> */
    public static function experiments(): array
    {
        return [
            'callback' => ['callback', [
                'ARITY_MISMATCH/signature/definite@tests/CourierTest.php:15',
                'TAUTOLOGY/mock_only/warning@tests/CourierTest.php:21',
            ]],
            'final' => ['final', [
                'RETURN_DRIFT/return_type/definite@tests/SealedTest.php:18',
                'TAUTOLOGY/mock_only/warning@tests/SealedTest.php:22',
                'TAUTOLOGY/zero_reach/warning@tests/SealedTest.php:16',
            ]],
            'fluent' => ['fluent', [
                'RETURN_DRIFT/return_type/definite@tests/QueryTest.php:15',
                'TAUTOLOGY/mock_only/warning@tests/QueryTest.php:19',
            ]],
            'mockery' => ['mockery', [
                'ARITY_MISMATCH/call_arity/definite@tests/NotifierTest.php:14',
                'TAUTOLOGY/mock_only/warning@tests/NotifierTest.php:15',
            ]],
            'partial-mock' => ['partial-mock', [
                'GHOST_MEMBER/missing_member/definite@tests/LedgerTest.php:15',
                'GHOST_MEMBER/missing_member/definite@tests/LedgerTest.php:18',
                'RETURN_DRIFT/return_type/definite@tests/LedgerTest.php:23',
                'GHOST_MEMBER/missing_member/definite@tests/LedgerTest.php:24',
                'TAUTOLOGY/mock_only/warning@tests/LedgerTest.php:27',
                'TAUTOLOGY/mock_only/warning@tests/LedgerTest.php:28',
                'TAUTOLOGY/mock_only/warning@tests/LedgerTest.php:29',
            ]],
            'pest' => ['pest', [
                'GHOST_MEMBER/missing_member/definite@tests/FeatureFlagTest.php:7',
                'TAUTOLOGY/mock_only/warning@tests/FeatureFlagTest.php:8',
            ]],
            'phpunit-stub' => ['phpunit-stub', [
                'GHOST_MEMBER/missing_member/definite@tests/InvoiceGatewayTest.php:13',
                'RETURN_DRIFT/return_type/definite@tests/InvoiceGatewayTest.php:14',
                'TAUTOLOGY/mock_only/warning@tests/InvoiceGatewayTest.php:15',
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

    public function testFindingsAreDeterministic(): void
    {
        $ids = static fn(PDO $pdo): array => $pdo->query('SELECT id FROM test_findings ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        [$first, , $root] = $this->scanned('momus');
        $second = $this->freshTestDatabase();
        (new ProjectScanService($second, self::repositoryRoot(), [$root]))->scan($root);

        self::assertGreaterThan(20, count(self::findings($first)));
        assertCount(count(self::findings($first)), $ids($first));
        assertSame($ids($first), $ids($second));
    }

    /**
     * Renaming a stubbed method in the production interface makes the
     * Mockery double's member a ghost, and renaming it back clears it; the
     * test file is never edited, and every other finding stays as it was.
     */
    public function testARenameInTheTargetMakesAndClearsAGhost(): void
    {
        [$pdo, $service, $root] = $this->scanned('nemesis');
        $before = self::findings($pdo);
        $production = $root . '/src/PaymentGateway.php';
        $test = (string) file_get_contents($root . '/tests/BillingServiceTest.php');
        $original = (string) file_get_contents($production);

        file_put_contents($production, str_replace('function refund(', 'function refundTransaction(', $original));
        $renamed = $service->scan($root);

        // Only the edited file is parsed again; the test file's contribution is reused and judged against it.
        assertSame([1, 1], [$renamed->data['parsed_files'], $renamed->data['unchanged_files']]);
        assertSame(self::sorted([...$before, 'GHOST_MEMBER/missing_member/definite@tests/BillingServiceTest.php:35']), self::findings($pdo));

        file_put_contents($production, $original);
        $service->scan($root);

        assertSame($before, self::findings($pdo));
        assertSame($test, (string) file_get_contents($root . '/tests/BillingServiceTest.php'));
    }

    /** `audit_tests` lists the PHP findings, and counts them for PHP rather than saying it has no test facts. */
    public function testAuditTestsListsPhpFindings(): void
    {
        [$pdo] = $this->scanned('nemesis');
        $projectId = (string) $pdo->query('SELECT id FROM projects')->fetchColumn();
        $audit = ArchitectureQueryService::forDatabase($pdo)->services()->testFidelityQueries->auditTests($projectId);

        assertSame(6, $audit->data['total']);
        assertSame(['tests/BillingServiceTest.php'], array_values(array_unique(array_column($audit->data['items'], 'file'))));
        assertSame(['definite' => 3, 'warning' => 3], $audit->data['summary']['by_language']['php']);
    }
}
