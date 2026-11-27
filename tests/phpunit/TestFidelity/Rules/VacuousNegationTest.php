<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity\Rules;

use Knossos\Scanner\Protocol\{Confidence, Evidence, NodeFact, Origin};
use Knossos\TestFidelity\FindingCategory;
use Knossos\TestFidelity\FindingConfidence;
use Knossos\TestFidelity\Rules\TautologyRule;
use Knossos\TestFidelity\TestFidelityGraph;
use Knossos\TestFidelity\TestFinding;
use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * TautologyRule's `vacuous_negation` (G3-02): a call matcher under `.not` that
 * lists fewer arguments than the member requires cannot fail.
 */
#[Group('test-fidelity')]
final class VacuousNegationTest extends KnossosTestCase
{
    public function testANegatedCallMatcherNoCallCanMeetIsAVacuousAssertion(): void
    {
        // G3-02: `expect(spy).not.toHaveBeenCalledWith()` for `rate(x)` holds that no call lists nothing, which none can.
        $findings = [...(new TautologyRule())->findings(self::rateGraph([['api' => 'toHaveBeenCalledWith', 'line' => 8, 'count' => 0, 'spread' => false, 'negated' => true]]))];

        assertCount(1, $findings);
        self::assertFinding($findings[0], 'vacuous_negation', FindingConfidence::Warning);
        assertSame('rate needs 1 argument(s); the negated toHaveBeenCalledWith expects 0, so it cannot fail', $findings[0]->message);
        assertSame([8, 'tests/a.test.ts'], [$findings[0]->evidence->startLine, $findings[0]->evidence->relativePath]);
        assertSame(['member' => 'rate', 'api' => 'toHaveBeenCalledWith'], $findings[0]->attributes);
        assertSame(['ts:test_double:tests/a.test.ts#double:6:3', 'ts:class:src/UserService.ts#UserService'], [$findings[0]->doubleOrCaseReference, $findings[0]->targetReference]);
    }

    public function testAVacuousNegationIsTheOneFindingOfItsAssertion(): void
    {
        // The assertion on that line would be mock-only too; the narrower finding wins, and another line keeps its own.
        $assertions = [
            CaseFixtures::assertion('toHaveBeenCalledWith', [CaseFixtures::operand('mock-call', ['double' => 'tests/a.test.ts#double:6:3'])], true, 8),
            CaseFixtures::assertion('toHaveBeenCalled', [CaseFixtures::operand('mock-call', ['double' => 'tests/a.test.ts#double:6:3'])], false, 9),
        ];
        $case = DriftFixtures::node('test_case', 'tests/a.test.ts#test:5', 'checks', ['name' => 'checks', 'production_calls' => 0, 'subjects' => [], 'assertions' => $assertions], 5);
        $matchers = [['api' => 'toHaveBeenCalledWith', 'line' => 8, 'count' => 0, 'spread' => false, 'negated' => true]];
        // Two stubs of the member, each with the matcher: one finding for the line.
        $graph = DriftFixtures::graph(
            [DriftFixtures::method('rate', ['signature' => ['params' => [['name' => 'x', 'type' => 'number']], 'returns' => 'number']])],
            [DriftFixtures::stub('rate', calledWith: $matchers), DriftFixtures::stub('rate', calledWith: $matchers)],
            extra: [$case],
        );

        assertSame(['mock_only 9', 'vacuous_negation 8'], array_map(
            static fn(TestFinding $finding): string => $finding->subRule . ' ' . $finding->evidence->startLine,
            [...(new TautologyRule())->findings($graph)],
        ));
    }

    public function testTheFirstVacuousNegationOfALineIsKeptAndEveryLineHasItsOwn(): void
    {
        $matcher = static fn(int $line): array => [['api' => 'toHaveBeenCalledWith', 'line' => $line, 'count' => 0, 'spread' => false, 'negated' => true]];
        $signature = ['signature' => ['params' => [['name' => 'x', 'type' => 'number']], 'returns' => 'number']];
        $graph = DriftFixtures::graph(
            [DriftFixtures::method('rate', $signature), DriftFixtures::method('total', $signature)],
            [DriftFixtures::stub('rate', calledWith: $matcher(8)), DriftFixtures::stub('total', calledWith: $matcher(8)), DriftFixtures::stub('total', calledWith: $matcher(11))],
        );

        assertSame(['8 rate', '11 total'], array_map(
            static fn(TestFinding $finding): string => $finding->evidence->startLine . ' ' . $finding->attributes['member'],
            [...(new TautologyRule())->findings($graph)],
        ));
    }

    public function testANegatedCallMatcherOnAMemberWithARestParameterIsSilent(): void
    {
        // As for arity drift, a rest parameter leaves the count unjudged, a required one before it too.
        $graph = DriftFixtures::graph(
            [DriftFixtures::method('rate', ['signature' => ['params' => [['name' => 'x', 'type' => 'number'], ['name' => 'more', 'type' => 'number[]', 'variadic' => true]], 'returns' => 'number']])],
            [DriftFixtures::stub('rate', calledWith: [['api' => 'toHaveBeenCalledWith', 'line' => 8, 'count' => 0, 'spread' => false, 'negated' => true]])],
        );

        assertSame([], [...(new TautologyRule())->findings($graph)]);
    }

    public function testAVacuousNegationInATestThatIsMeantToFailIsSilent(): void
    {
        // Fix round 1 (G1-10): `it.fails` passes only when an assertion fails, so a vacuous one in it is no tautology.
        $case = static fn(bool $fails, int $line, int $end, string $file = 'tests/a.test.ts'): NodeFact => new NodeFact(
            'ts:test_case:' . $file . '#test:' . $line,
            'test_case',
            $file . '#test:' . $line,
            'checks',
            Origin::Ast,
            Confidence::Certain,
            new Evidence($file, $line, $end),
            ['name' => 'checks', 'production_calls' => 1, 'subjects' => [], 'fails' => $fails, 'assertions' => []],
        );
        $matchers = [['api' => 'toHaveBeenCalledWith', 'line' => 8, 'count' => 0, 'spread' => false, 'negated' => true]];
        $subRules = static fn(array $cases): array => self::subRules([...(new TautologyRule())->findings(self::rateGraph($matchers, $cases))]);

        assertSame([], $subRules([$case(true, 5, 9)]));
        assertSame([], $subRules([$case(true, 8, 8)]), 'a range holds its first and last line');
        assertSame(['vacuous_negation'], $subRules([$case(false, 5, 9)]), 'the control: a plain test still warns');
        assertSame(['vacuous_negation'], $subRules([$case(true, 9, 12)]), 'a fails test after the matcher says nothing of it');
        assertSame(['vacuous_negation'], $subRules([$case(true, 2, 7)]), 'nor one that ends before it');
        assertSame(['vacuous_negation'], $subRules([$case(true, 1, 20, 'tests/b.test.ts')]), 'nor one in another file');
        assertSame([], $subRules([$case(true, 1, 20, 'tests/0.test.ts'), $case(true, 5, 9)]), 'each file keeps its own');
    }

    public function testANegatedCallMatcherSomeCallCouldMeetIsSilent(): void
    {
        $matcher = static fn(array $fields): array => [['api' => 'toHaveBeenCalledWith', 'line' => 8, 'count' => 0, 'spread' => false, 'negated' => true, ...$fields]];
        $judge = static fn(array $matchers, array $params = [['name' => 'x', 'type' => 'number']]): array => self::subRules([...(new TautologyRule())->findings(self::rateGraph($matchers, params: $params))]);

        assertSame([], $judge($matcher(['negated' => false])), 'a plain matcher with too few is arity drift, not this');
        assertSame([], $judge($matcher(['count' => 1])));
        assertSame([], $judge($matcher(['count' => 2])), 'too many is arity drift, not this');
        assertSame([], $judge($matcher(['spread' => true])));
        assertSame([], $judge($matcher([]), [['name' => 'xs', 'type' => 'number[]', 'variadic' => true]]));
        assertSame([], $judge($matcher([]), [['name' => 'x', 'type' => 'number', 'optional' => true]]));
        assertSame(['vacuous_negation'], $judge($matcher(['api' => 'toHaveBeenNthCalledWith'])), 'the control');
    }

    /**
     * `UserService.rate(x)` spied in `tests/a.test.ts`, with the given call matchers on it.
     *
     * @param list<array<string, mixed>> $calledWith
     * @param list<NodeFact> $extra further test nodes
     * @param list<array<string, mixed>> $params
     */
    private static function rateGraph(array $calledWith, array $extra = [], array $params = [['name' => 'x', 'type' => 'number']]): TestFidelityGraph
    {
        return DriftFixtures::graph(
            [DriftFixtures::method('rate', ['signature' => ['params' => $params, 'returns' => 'number']])],
            [DriftFixtures::stub('rate', calledWith: $calledWith)],
            extra: $extra,
        );
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
