<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity\Rules;

use Knossos\Scanner\Protocol\NodeFact;
use Knossos\TestFidelity\FindingCategory;
use Knossos\TestFidelity\FindingConfidence;
use Knossos\TestFidelity\Rules\TautologyRule;
use Knossos\TestFidelity\TestFinding;
use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

#[Group('test-fidelity')]
final class TautologyRuleTest extends KnossosTestCase
{
    private const SPY = CaseFixtures::FILE . '#double:6:5';

    public function testMockEchoIsDefinite(): void
    {
        $findings = self::judge(CaseFixtures::testCase([CaseFixtures::assertion('toBe', [
            CaseFixtures::operand('mock-config', ['value' => '42', 'double' => self::SPY]),
            CaseFixtures::operand('literal', ['text' => '42']),
        ])]));

        assertCount(1, $findings);
        self::assertFinding($findings[0], 'mock_echo', FindingConfidence::Definite);
        assertSame('the assertion compares the stubbed value 42 with itself; it cannot fail', $findings[0]->message);
        assertSame('ts:test_case:tests/a.test.ts#test:10', $findings[0]->doubleOrCaseReference);
        assertNull($findings[0]->targetReference);
        assertSame([CaseFixtures::FILE, 12, 12], [$findings[0]->evidence->relativePath, $findings[0]->evidence->startLine, $findings[0]->evidence->endLine]);
        assertSame(['api' => 'toBe'], $findings[0]->attributes);
    }

    public function testMockEchoReadsEitherSide(): void
    {
        $findings = self::judge(CaseFixtures::testCase([CaseFixtures::assertion('toEqual', [
            CaseFixtures::operand('literal', ['text' => "'ok'"]),
            CaseFixtures::operand('mock-config', ['value' => "'ok'"]),
        ])], 1));

        assertSame(['mock_echo'], self::subRules($findings));
    }

    public function testMockEchoTwinsAreSilent(): void
    {
        $operands = static fn(string $literal): array => [
            CaseFixtures::operand('mock-config', ['value' => '42']),
            CaseFixtures::operand('literal', ['text' => $literal]),
        ];

        assertSame([], self::subRules(self::judge(CaseFixtures::testCase([
            CaseFixtures::assertion('toBe', $operands('43')),
            CaseFixtures::assertion('toBe', $operands('42'), true),
            CaseFixtures::assertion('toBe', [CaseFixtures::operand('mock-config'), CaseFixtures::operand('literal', ['text' => '42'])]),
            CaseFixtures::assertion('toBe', [CaseFixtures::operand('mock-config', ['value' => '42']), CaseFixtures::operand('unknown', ['text' => '42'])]),
            CaseFixtures::assertion('toBe', [CaseFixtures::operand('unknown', ['value' => '42']), CaseFixtures::operand('literal', ['text' => '42'])]),
        ], 1))));
    }

    public function testSelfComparisonIsDefinite(): void
    {
        $findings = self::judge(CaseFixtures::testCase([CaseFixtures::assertion('toEqual', [
            CaseFixtures::operand('unknown', ['text' => 'user']),
            CaseFixtures::operand('unknown', ['text' => 'user']),
        ])], 1));

        assertCount(1, $findings);
        self::assertFinding($findings[0], 'self_comparison', FindingConfidence::Definite);
        assertSame('user is compared with itself; the assertion cannot fail', $findings[0]->message);
    }

    public function testTrueComparedWithTrueIsOneSelfComparison(): void
    {
        // Nemesis's UserService.spec.ts:32, `expect(true).toBe(true)`: self-comparison and constant both match; the more specific wins.
        $findings = self::judge(CaseFixtures::testCase([CaseFixtures::assertion('toBe', [
            CaseFixtures::operand('literal', ['text' => 'true']),
            CaseFixtures::operand('literal', ['text' => 'true']),
        ])], 1));

        assertSame(['self_comparison'], self::subRules($findings));
    }

    public function testSelfComparisonTwinsAreSilent(): void
    {
        assertSame([], self::subRules(self::judge(CaseFixtures::testCase([
            // Evaluated twice, a call may differ: the worker sends no text.
            CaseFixtures::assertion('toBe', [CaseFixtures::operand('production'), CaseFixtures::operand('production')]),
            CaseFixtures::assertion('toBe', [CaseFixtures::operand('unknown', ['text' => 'user']), CaseFixtures::operand('unknown')]),
            CaseFixtures::assertion('toBe', [CaseFixtures::operand('unknown', ['text' => 'a']), CaseFixtures::operand('unknown', ['text' => 'b'])]),
            CaseFixtures::assertion('toEqual', [CaseFixtures::operand('unknown', ['text' => 'user']), CaseFixtures::operand('unknown', ['text' => 'user'])], true),
            CaseFixtures::assertion('toBeDefined', [CaseFixtures::operand('unknown', ['text' => 'user'])]),
        ], 1))));
    }

    public function testConstantIsDefinite(): void
    {
        $findings = self::judge(CaseFixtures::testCase([CaseFixtures::assertion('toBeGreaterThan', [
            CaseFixtures::operand('literal', ['text' => 'limit']),
            CaseFixtures::operand('literal', ['text' => '1']),
        ])], 1));

        assertCount(1, $findings);
        self::assertFinding($findings[0], 'constant', FindingConfidence::Definite);
        assertSame('limit is compared with 1, both constants; the assertion cannot fail', $findings[0]->message);
    }

    public function testConstantTwinsAreSilent(): void
    {
        assertSame([], self::subRules(self::judge(CaseFixtures::testCase([
            CaseFixtures::assertion('toHaveLength', [CaseFixtures::operand('literal', ['text' => "'ab'"]), CaseFixtures::operand('literal', ['text' => '2'])]),
            CaseFixtures::assertion('toBe', [CaseFixtures::operand('production', ['text' => 'cart.total']), CaseFixtures::operand('literal', ['text' => '1'])]),
            CaseFixtures::assertion('toBe', [CaseFixtures::operand('literal', ['text' => '1'])]),
        ], 1))));
    }

    public function testPhpAssertionsOfOneValueAreNotConstant(): void
    {
        foreach (['assertNull', 'assertInstanceOf', 'assertCount', 'toHaveCount'] as $api) {
            assertSame([], self::subRules(self::judge(CaseFixtures::testCase([CaseFixtures::assertion($api, [CaseFixtures::operand('literal', ['text' => '2']), CaseFixtures::operand('literal', ['text' => '[1, 2]'])])], 1))), $api);
        }
        assertSame(['constant'], self::subRules(self::judge(CaseFixtures::testCase([CaseFixtures::assertion('assertSame', [CaseFixtures::operand('literal', ['text' => '2']), CaseFixtures::operand('literal', ['text' => '[1, 2]'])])], 1))));
    }

    public function testNegatedConstantIsStillConstant(): void
    {
        $findings = self::judge(CaseFixtures::testCase([CaseFixtures::assertion('toBe', [
            CaseFixtures::operand('literal', ['text' => '1']),
            CaseFixtures::operand('literal', ['text' => '2']),
        ], true)], 1));

        assertSame(['constant'], self::subRules($findings));
    }

    public function testMockOnlyIsWarning(): void
    {
        $findings = self::judge(CaseFixtures::testCase([CaseFixtures::assertion('toEqual', [
            CaseFixtures::operand('mock-call', ['double' => self::SPY]),
            CaseFixtures::operand('mock-config', ['value' => 'rows']),
        ])]));

        assertCount(1, $findings);
        self::assertFinding($findings[0], 'mock_only', FindingConfidence::Warning);
        assertSame('every operand comes from a double and the test runs no production code', $findings[0]->message);
    }

    public function testMockOnlyTwinsAreSilent(): void
    {
        $mockOnly = CaseFixtures::assertion('toEqual', [CaseFixtures::operand('mock-call'), CaseFixtures::operand('mock-config', ['value' => 'rows'])]);

        assertSame([], self::subRules(self::judge(CaseFixtures::testCase([$mockOnly], 1))));
        assertSame([], self::subRules(self::judge(CaseFixtures::testCase([
            CaseFixtures::assertion('toEqual', [CaseFixtures::operand('mock-call'), CaseFixtures::operand('unknown')]),
            CaseFixtures::assertion('toBeDefined', []),
        ]))));
    }

    public function testUnconfiguredSpyIsWarning(): void
    {
        $spy = CaseFixtures::double(6, members: [CaseFixtures::member('total', 0)]);
        $findings = self::judge(CaseFixtures::testCase([CaseFixtures::assertion('toHaveBeenCalledWith', [
            CaseFixtures::operand('mock-call', ['text' => 'spy', 'double' => self::SPY, 'unconfigured_spy' => true]),
            CaseFixtures::operand('literal', ['text' => '1']),
        ])]), $spy);

        assertCount(1, $findings);
        self::assertFinding($findings[0], 'unconfigured_spy', FindingConfidence::Warning);
        assertSame('the spy is not stubbed and the test runs no production code, so nothing could have called it', $findings[0]->message);
        assertNull($findings[0]->targetReference);
        assertSame(['api' => 'toHaveBeenCalledWith', 'double' => self::SPY], $findings[0]->attributes);
    }

    public function testUnconfiguredSpyTwinsAreSilent(): void
    {
        $assertion = CaseFixtures::assertion('toHaveBeenCalled', [CaseFixtures::operand('mock-call', ['double' => self::SPY, 'unconfigured_spy' => true])]);
        $spy = CaseFixtures::double(6, members: [CaseFixtures::member('total', 0)]);

        // The test runs production: the spy may be reached through it.
        assertSame([], self::subRules(self::judge(CaseFixtures::testCase([$assertion], 1), $spy)));
        // The spy is called in the file, or its double is not in the scan: only the mock-only rule is left.
        assertSame(['mock_only'], self::subRules(self::judge(CaseFixtures::testCase([$assertion]), CaseFixtures::double(6, members: [CaseFixtures::member('total', 0)], invocations: 1))));
        assertSame(['mock_only'], self::subRules(self::judge(CaseFixtures::testCase([$assertion]))));
        // A spy the worker does not call unconfigured.
        assertSame(['mock_only'], self::subRules(self::judge(CaseFixtures::testCase([CaseFixtures::assertion('toHaveBeenCalled', [CaseFixtures::operand('mock-call', ['double' => self::SPY])])]), $spy)));
        // Not a call matcher.
        assertSame(['mock_only'], self::subRules(self::judge(CaseFixtures::testCase([CaseFixtures::assertion('toBeDefined', [CaseFixtures::operand('mock-call', ['double' => self::SPY, 'unconfigured_spy' => true])])]), $spy)));
    }

    public function testAMockerySpyAssertionOnAnUnconfiguredSpyIsWarning(): void
    {
        $spy = CaseFixtures::double(6, members: [CaseFixtures::member('charge', 0)]);
        $operand = CaseFixtures::operand('mock-call', ['text' => '$spy', 'double' => self::SPY, 'unconfigured_spy' => true]);

        foreach (['shouldHaveReceived', 'shouldNotHaveReceived'] as $api) {
            $findings = self::judge(CaseFixtures::testCase([CaseFixtures::assertion($api, [$operand])]), $spy);
            assertSame(['unconfigured_spy'], self::subRules($findings), $api);
            assertSame(['api' => $api, 'double' => self::SPY], $findings[0]->attributes);
        }
    }

    public function testSelfComparisonOutranksUnconfiguredSpy(): void
    {
        $spy = CaseFixtures::double(6, members: [CaseFixtures::member('total', 0)]);
        $operand = CaseFixtures::operand('mock-call', ['text' => 'spy', 'double' => self::SPY, 'unconfigured_spy' => true]);

        assertSame(['self_comparison'], self::subRules(self::judge(CaseFixtures::testCase([CaseFixtures::assertion('toHaveBeenCalledWith', [$operand, $operand])]), $spy)));
    }

    public function testAnAssertionOnTheFirstLineIsPlacedThere(): void
    {
        $findings = self::judge(CaseFixtures::testCase([CaseFixtures::assertion('toBe', [CaseFixtures::operand('literal', ['text' => '1']), CaseFixtures::operand('literal', ['text' => '1'])], line: 1)], 1));

        assertSame(1, $findings[0]->evidence->startLine);
    }

    public function testProductionOperandIsSilent(): void
    {
        assertSame([], self::judge(CaseFixtures::testCase([
            CaseFixtures::assertion('toBe', [CaseFixtures::operand('production'), CaseFixtures::operand('literal', ['text' => '5'])]),
            CaseFixtures::assertion('toEqual', [CaseFixtures::operand('production', ['text' => 'cart.items']), CaseFixtures::operand('mock-config', ['value' => '[]'])]),
            CaseFixtures::assertion('toHaveBeenCalled', [CaseFixtures::operand('mock-call', ['double' => self::SPY])]),
        ], 2), CaseFixtures::double(6, members: [CaseFixtures::member('total')], invocations: 1)));
    }

    public function testZeroReachIsWarning(): void
    {
        $case = CaseFixtures::testCase([CaseFixtures::assertion('toBe', [CaseFixtures::operand('unknown'), CaseFixtures::operand('literal', ['text' => '1'])])]);
        $findings = self::judge($case, CaseFixtures::double(6, members: [CaseFixtures::member('total')], testCase: $case->canonicalName));

        assertCount(1, $findings);
        self::assertFinding($findings[0], 'zero_reach', FindingConfidence::Warning);
        assertSame('ts:test_double:' . self::SPY, $findings[0]->doubleOrCaseReference);
        assertSame('vi.spyOn is configured but never invoked or asserted', $findings[0]->message);
        assertSame([CaseFixtures::FILE, 6], [$findings[0]->evidence->relativePath, $findings[0]->evidence->startLine]);
        assertSame(['pattern' => 'vi.spyOn'], $findings[0]->attributes);
    }

    public function testZeroReachTwinsAreSilent(): void
    {
        $quiet = CaseFixtures::testCase([CaseFixtures::assertion('toBe', [CaseFixtures::operand('unknown'), CaseFixtures::operand('literal', ['text' => '1'])])]);
        $busy = CaseFixtures::testCase([], 1, [], 20);
        $asserting = CaseFixtures::testCase([CaseFixtures::assertion('toBeDefined', [CaseFixtures::operand('unknown', ['double' => self::SPY])])], 0, [], 30);
        $pinned = [CaseFixtures::member('total')];

        // Called in the file.
        assertSame([], self::subRules(self::judge($quiet, CaseFixtures::double(6, members: $pinned, invocations: 2, testCase: $quiet->canonicalName))));
        // Nothing pinned.
        assertSame([], self::subRules(self::judge($quiet, CaseFixtures::double(6, members: [CaseFixtures::member('total', 0)], testCase: $quiet->canonicalName))));
        // Its test runs production, which may call it.
        assertSame([], self::subRules(self::judge($busy, CaseFixtures::double(6, members: $pinned, testCase: $busy->canonicalName))));
        // Made outside any test, in a file where a test runs production.
        assertSame([], self::subRules(self::judge($quiet, $busy, CaseFixtures::double(6, members: $pinned))));
        // Asserted on.
        assertSame([], self::subRules(self::judge($asserting, CaseFixtures::double(6, members: $pinned, testCase: $asserting->canonicalName))));
        // Made outside any test, in a file with no test.
        assertSame([], self::subRules(self::judge(CaseFixtures::double(6, members: $pinned))));
    }

    public function testZeroReachIsSilentOnADoubleOfAPackage(): void
    {
        // Code outside the project, which a test's production count does not
        // see, may reach what replaces a package export (Nemesis packageMock.spec.ts).
        $quiet = CaseFixtures::testCase([]);
        $package = CaseFixtures::double(6, target: ['kind' => 'module', 'specifier' => 'node:fs', 'member' => 'readFileSync'], members: [CaseFixtures::member('readFileSync')], testCase: $quiet->canonicalName);
        $local = CaseFixtures::double(7, target: ['kind' => 'module', 'specifier' => '../src/fs', 'member' => 'readFileSync'], members: [CaseFixtures::member('readFileSync')], testCase: $quiet->canonicalName);

        assertSame([], self::subRules(self::judge($quiet, $package)));
        assertSame(['zero_reach'], self::subRules(self::judge($quiet, $local)));
    }

    public function testASpyOnALibGlobalIsNotZeroReach(): void
    {
        // `vi.spyOn(console, 'log')` and `vi.spyOn(Date, 'now')`: the owner is declared in a lib file, which no
        // contribution emits, or in a project `.d.ts`; code outside the project may call either.
        $quiet = CaseFixtures::testCase([]);
        $spy = static fn(int $line, string $export, string $member): NodeFact => CaseFixtures::double($line, target: ['kind' => 'instance-member', 'export' => $export, 'member' => $member], members: [CaseFixtures::member($member)], testCase: $quiet->canonicalName);
        $console = $spy(6, 'Console', 'log');
        $date = $spy(7, 'DateConstructor', 'now');
        $local = $spy(8, 'Clock', 'now');
        $declared = DriftFixtures::node('interface', 'types/globals.d.ts#Clock', 'Clock', [], 1);
        $project = DriftFixtures::node('class', 'src/clock.ts#Clock', 'Clock', [], 1);
        $edges = [
            DriftFixtures::edge('doubles', $console, 'ts:external_class:Console'),
            DriftFixtures::edge('doubles', $date, 'ts:external_class:DateConstructor'),
        ];

        assertSame([], self::subRules([...(new TautologyRule())->findings(CaseFixtures::graph([$quiet, $console, $date], [], $edges))]));
        assertSame([], self::subRules([...(new TautologyRule())->findings(CaseFixtures::graph([$quiet, $local], [$declared], [DriftFixtures::edge('doubles', $local, $declared->localId)]))]));
        // A twin owned by project code is still judged.
        assertSame(['zero_reach'], self::subRules([...(new TautologyRule())->findings(CaseFixtures::graph([$quiet, $local], [$project], [DriftFixtures::edge('doubles', $local, $project->localId)]))]));
    }

    public function testZeroReachOfAFileLevelDoubleNeedsEveryTestOfItsFileQuiet(): void
    {
        $quiet = CaseFixtures::testCase([]);
        $elsewhere = CaseFixtures::testCase([], 3, [], 10, 'tests/b.test.ts');

        assertSame(['zero_reach'], self::subRules(self::judge($quiet, $elsewhere, CaseFixtures::double(6, members: [CaseFixtures::member('total')]))));
    }

    public function testFindingsFollowTheGraphOrder(): void
    {
        $echo = [CaseFixtures::operand('mock-config', ['value' => '1']), CaseFixtures::operand('literal', ['text' => '1'])];
        $findings = self::judge(
            CaseFixtures::testCase([CaseFixtures::assertion('toBe', $echo, line: 41)], 1, [], 40),
            CaseFixtures::testCase([CaseFixtures::assertion('toBe', $echo, line: 13), CaseFixtures::assertion('toEqual', $echo, line: 14)], 1),
        );

        assertSame([13, 14, 41], array_map(static fn(TestFinding $finding): int => $finding->evidence->startLine, $findings));
        assertSame([13, 14, 41], array_map(static fn(TestFinding $finding): int => $finding->evidence->startLine, self::judge(
            CaseFixtures::testCase([CaseFixtures::assertion('toBe', $echo, line: 13), CaseFixtures::assertion('toEqual', $echo, line: 14)], 1),
            CaseFixtures::testCase([CaseFixtures::assertion('toBe', $echo, line: 41)], 1, [], 40),
        )));
    }

    public function testIdNamesTheCategory(): void
    {
        assertSame('test.fidelity.tautology.v1', (new TautologyRule())->id());
    }

    /** @return list<TestFinding> */
    private static function judge(NodeFact ...$nodes): array
    {
        return [...(new TautologyRule())->findings(CaseFixtures::graph(array_values($nodes)))];
    }

    /**
     * @param list<TestFinding> $findings
     * @return list<string>
     */
    private static function subRules(array $findings): array
    {
        return array_map(static fn(TestFinding $finding): string => $finding->subRule, $findings);
    }

    private static function assertFinding(TestFinding $finding, string $subRule, FindingConfidence $confidence): void
    {
        assertSame(FindingCategory::Tautology, $finding->category);
        assertSame('test.fidelity.tautology.v1', $finding->ruleId);
        assertSame($subRule, $finding->subRule);
        assertSame($confidence, $finding->confidence);
    }
}
