<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity\Rules;

use Knossos\TestFidelity\FindingConfidence;
use Knossos\TestFidelity\Rules\ArityMismatchRule;
use Knossos\TestFidelity\TestFidelityGraph;
use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

#[Group('test-fidelity')]
final class ArityMismatchRuleTest extends KnossosTestCase
{
    /**
     * @param array<string, mixed> $signature
     * @param list<mixed> $params
     * @return list<\Knossos\TestFidelity\TestFinding>
     */
    private static function judge(array $signature, array $params): array
    {
        $graph = DriftFixtures::graph([DriftFixtures::method('find', ['signature' => $signature])], [DriftFixtures::stub('find', $params)]);

        return [...(new ArityMismatchRule())->findings($graph)];
    }

    public function testBothSidesTypedIsDefinite(): void
    {
        $findings = self::judge(['params' => [['name' => 'id', 'type' => 'string']]], [['name' => 'id', 'type' => 'string'], ['name' => 'extra', 'type' => 'number']]);

        assertCount(1, $findings);
        assertSame(FindingConfidence::Definite, $findings[0]->confidence);
        assertSame('find takes 1 argument(s); the double passes 2', $findings[0]->message);
    }

    /**
     * @param array<string, mixed> $signature
     * @param list<array{api: string, line: int, count: int, spread: bool}> $matchers
     * @return list<\Knossos\TestFidelity\TestFinding>
     */
    private static function judgeCalls(array $signature, array $matchers, string $framework = 'vitest', ?string $node = null): array
    {
        $graph = DriftFixtures::graph(
            [DriftFixtures::method('find', ['signature' => $signature] + ($node === 'overloaded' ? ['overloaded' => true] : []))],
            [DriftFixtures::stub('find', null, [], $matchers)],
            framework: $framework,
        );

        return [...(new ArityMismatchRule())->findings($graph)];
    }

    /** @return array{api: string, line: int, count: int, spread: bool} */
    private static function matcher(int $count, string $api = 'toHaveBeenCalledWith', bool $spread = false): array
    {
        return ['api' => $api, 'line' => 31, 'count' => $count, 'spread' => $spread];
    }

    public function testTooManyMatcherArgumentsIsDefinite(): void
    {
        $findings = self::judgeCalls(['params' => [['name' => 'id'], ['name' => 'patch']]], [self::matcher(3)]);

        assertCount(1, $findings);
        assertSame(FindingConfidence::Definite, $findings[0]->confidence);
        assertSame('call_arity', $findings[0]->subRule);
        assertSame('find takes 2 argument(s); the matcher expects 3', $findings[0]->message);
        assertSame(7, $findings[0]->evidence->startLine);
    }

    public function testTooFewIsDefinite(): void
    {
        $findings = self::judgeCalls(['params' => [['name' => 'id'], ['name' => 'patch'], ['name' => 'deep', 'optional' => true]]], [self::matcher(1)]);

        assertCount(1, $findings);
        assertSame(FindingConfidence::Definite, $findings[0]->confidence);
        assertSame('call_arity', $findings[0]->subRule);
        assertSame('find needs 2 argument(s); the matcher expects 1', $findings[0]->message);
    }

    public function testAnArgumentCountWithinTheSignatureIsSilent(): void
    {
        $signature = ['params' => [['name' => 'id'], ['name' => 'deep', 'optional' => true]]];

        assertSame([], self::judgeCalls($signature, [self::matcher(1), self::matcher(2)]));
    }

    public function testSpreadIsSilent(): void
    {
        assertSame([], self::judgeCalls(['params' => [['name' => 'id']]], [self::matcher(3, 'toHaveBeenCalledWith', true)]));
    }

    public function testVariadicTargetIsSilent(): void
    {
        assertSame([], self::judgeCalls(['params' => [['name' => 'ids', 'variadic' => true]]], [self::matcher(5)]));
    }

    public function testASignatureThatReadsItsArgumentsAsAListIsSilent(): void
    {
        // PHP's `function log() { $args = func_get_args(); }` takes any number past its parameters.
        assertSame([], self::judgeCalls(['params' => [['name' => 'message']], 'variadic' => true], [self::matcher(3, 'with')], 'phpunit'));
        assertCount(1, self::judgeCalls(['params' => [['name' => 'message']]], [self::matcher(3, 'with')], 'phpunit'));
    }

    public function testTruncatedSignatureIsSilentForAMatcher(): void
    {
        assertSame([], self::judgeCalls(['params' => [['name' => 'id']], 'truncated' => true], [self::matcher(3)]));
    }

    public function testAPhpunitWithIsJudgedOnlyForTooMany(): void
    {
        $signature = ['params' => [['name' => 'id'], ['name' => 'patch']]];

        assertSame([], self::judgeCalls($signature, [self::matcher(1, 'with')], 'phpunit'));
        assertCount(1, self::judgeCalls($signature, [self::matcher(3, 'with')], 'phpunit'));
        assertCount(1, self::judgeCalls($signature, [self::matcher(1, 'with')], 'mockery'));
    }

    public function testTheFirstOffendingMatcherIsReported(): void
    {
        $findings = self::judgeCalls(['params' => [['name' => 'id']]], [self::matcher(1), self::matcher(4), self::matcher(2)]);

        assertCount(1, $findings);
        assertSame('find takes 1 argument(s); the matcher expects 4', $findings[0]->message);
    }

    public function testUntypedSideIsAWarning(): void
    {
        $findings = self::judge(['params' => [['name' => 'id', 'type' => 'string']]], [['name' => 'id'], ['name' => 'extra']]);

        assertSame(FindingConfidence::Warning, $findings[0]->confidence);
    }

    public function testTruncatedSignatureIsSilent(): void
    {
        assertSame([], self::judge(['params' => [['name' => 'id']], 'truncated' => true], [['name' => 'a'], ['name' => 'b']]));
    }

    public function testRestParameterAcceptsAnyNumber(): void
    {
        assertSame([], self::judge(['params' => [['name' => 'ids', 'variadic' => true]]], [['name' => 'a'], ['name' => 'b'], ['name' => 'c']]));
    }

    public function testFewerParametersIsOrdinary(): void
    {
        assertSame([], self::judge(['params' => [['name' => 'a'], ['name' => 'b']]], [['name' => 'a']]));
    }

    public function testAnOverloadedMemberIsSilentWhateverTheFirstSignatureSays(): void
    {
        // `find(id)` and `find(id, deep)`: the node holds the first, the double matches the second.
        $graph = DriftFixtures::graph(
            [DriftFixtures::method('find', ['signature' => ['params' => [['name' => 'id', 'type' => 'string']]], 'overloaded' => true])],
            [DriftFixtures::stub('find', [['name' => 'id', 'type' => 'string'], ['name' => 'deep', 'type' => 'boolean']])],
        );

        assertSame([], [...(new ArityMismatchRule())->findings($graph)]);
    }

    public function testOptionalDefaultedAndRestParametersOfTheDoubleAreNotRequired(): void
    {
        $declared = ['params' => [['name' => 'id', 'type' => 'string']]];

        assertSame([], self::judge($declared, [['name' => 'id', 'type' => 'string'], ['name' => 'opts', 'type' => 'Opts', 'optional' => true]]));
        assertSame([], self::judge($declared, [['name' => 'id', 'type' => 'string'], ['name' => 'rest', 'type' => 'string[]', 'variadic' => true]]));
        assertCount(1, self::judge($declared, [['name' => 'id'], ['name' => 'extra'], ['name' => 'opts', 'optional' => true]]));
    }

    public function testNoDoubleParametersOrNoSignatureIsSilent(): void
    {
        $graph = DriftFixtures::graph([DriftFixtures::method('find')], [DriftFixtures::stub('find', [['name' => 'a']]), DriftFixtures::stub('other')]);

        assertSame([], [...(new ArityMismatchRule())->findings($graph)]);
        assertSame([], self::findings(DriftFixtures::graph([DriftFixtures::method('find', ['signature' => ['params' => []]])], [DriftFixtures::stub('find')])));
    }

    /** @return list<\Knossos\TestFidelity\TestFinding> */
    private static function findings(TestFidelityGraph $graph): array
    {
        return [...(new ArityMismatchRule())->findings($graph)];
    }
}
