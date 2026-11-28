<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity\Rules;

use Knossos\TestFidelity\Rules\ArityMismatchRule;
use Knossos\TestFidelity\TestFinding;
use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * A call matcher that lists a literal for a parameter typed by a PHP enum
 * whose case list is closed, and the literal is none of its cases
 * (`ARITY_MISMATCH/enum_case`, G3-19): definite when the literal's type is
 * the enum's backing type, or a string naming no case of a pure enum; a
 * warning when the types differ; silent wherever the evidence runs out.
 */
#[Group('test-fidelity')]
final class EnumCaseCheckTest extends KnossosTestCase
{
    private const LEG = [['name' => 'Air', 'value' => 'air'], ['name' => 'Sea', 'value' => 'sea']];

    /**
     * What the arity rule finds on `reroute(<$type> $to)` matched with the given literals.
     *
     * @param array<string, mixed> $enum the enum node's attributes
     * @param list<mixed> $literals
     * @param array<string, mixed> $matcher overrides of the matcher
     * @return list<string> each as `confidence sub_rule@line message`
     */
    private static function judge(array $enum, array $literals, string $type = 'App\Leg', array $matcher = [], string $framework = 'phpunit', string $enumKind = 'enum'): array
    {
        $signature = ['signature' => ['params' => [['name' => 'to', 'type' => $type], ['name' => 'rest', 'type' => 'string', 'variadic' => true]], 'returns' => 'bool']];
        $member = ['name' => 'reroute', 'line' => 14, 'api' => 'method', 'returns' => [], 'called_with' => [[
            'api' => 'with', 'line' => 15, 'count' => count($literals), 'spread' => false, 'literals' => $literals, ...$matcher,
        ]]];
        $graph = DriftFixtures::phpGraph([['reroute', $signature]], [$member], ['framework' => $framework], extra: [DriftFixtures::phpType($enumKind, 'App\Leg', $enum, 'src/Leg.php')]);

        return array_map(
            static fn(TestFinding $finding): string => sprintf('%s %s/%s@%d %s', $finding->confidence->value, $finding->category->value, $finding->subRule, $finding->evidence->startLine, $finding->message),
            [...(new ArityMismatchRule())->findings($graph)],
        );
    }

    /** @return array{type: string, value: string} */
    private static function string(string $value): array
    {
        return ['type' => 'string', 'value' => $value];
    }

    /** @return array{type: string, value: string} */
    private static function int(string $value): array
    {
        return ['type' => 'int', 'value' => $value];
    }

    /** Nemesis's `experiments/php/enum-value` `ShipmentTest.php:15`: `with('rail')` for a `Leg`, whose cases are `air` and `sea`. */
    public function testALiteralNoCaseOfABackedEnumIsDefinite(): void
    {
        $backed = ['cases' => self::LEG, 'backing' => 'string'];

        assertSame(["definite ARITY_MISMATCH/enum_case@15 reroute takes a Leg as argument 1, and 'rail' is none of its cases (with)"], self::judge($backed, [self::string('rail')]));
        assertSame(["definite ARITY_MISMATCH/enum_case@15 reroute takes a Leg as argument 1, and 'rail' is none of its cases (with)"], self::judge($backed, [self::string('rail')], framework: 'mockery'));
        assertSame(['definite ARITY_MISMATCH/enum_case@15 reroute takes a Leg as argument 1, and 7 is none of its cases (with)'], self::judge(['cases' => [['name' => 'Low', 'value' => '1']], 'backing' => 'int'], [self::int('7')]));
    }

    public function testAValidCaseIsSilent(): void
    {
        assertSame([], self::judge(['cases' => self::LEG, 'backing' => 'string'], [self::string('air')]));
        assertSame([], self::judge(['cases' => [['name' => 'Low', 'value' => '1']], 'backing' => 'int'], [self::int('1')]));
    }

    public function testANonLiteralIsSilent(): void
    {
        assertSame([], self::judge(['cases' => self::LEG, 'backing' => 'string'], [null]));
        assertSame([], self::judge(['cases' => self::LEG, 'backing' => 'string'], []), 'A matcher that sends no literals says nothing.');
    }

    /** An open list, a missing one, an empty one, or an enum whose backing is not stated, is nothing to hold a literal against. */
    public function testAnOpenOrUnknownCaseListIsSilent(): void
    {
        $rail = [self::string('rail')];

        assertSame([], self::judge(['cases' => self::LEG, 'backing' => 'string', 'cases_truncated' => true], $rail));
        assertSame([], self::judge(['backing' => 'string'], $rail));
        assertSame([], self::judge(['cases' => [], 'backing' => 'string'], $rail));
        assertSame([], self::judge(['cases' => self::LEG], $rail), 'A worker that does not say the backing type leaves it unknown.');
        assertSame([], self::judge(['cases' => [['name' => 'A'], ['name' => 'B']], 'backing' => 'string'], [self::string('c')]), 'A backed enum with a computed case sends no values.');
    }

    /** A pure enum is pinned by its case names: a name it has is silent, one it lacks definite. */
    public function testAPureEnumIsComparedByName(): void
    {
        $pure = ['cases' => [['name' => 'Hearts'], ['name' => 'Spades']], 'backing' => 'none'];

        assertSame([], self::judge($pure, [self::string('Hearts')]));
        assertSame(["definite ARITY_MISMATCH/enum_case@15 reroute takes a Leg as argument 1, and 'Clubs' is none of its cases (with)"], self::judge($pure, [self::string('Clubs')]));
        assertSame(['warning ARITY_MISMATCH/enum_case@15 reroute takes a Leg as argument 1, and 3 is none of its cases (with)'], self::judge($pure, [self::int('3')]));
    }

    /** A literal of another type than the backing is never a case, but may be a value PHP coerces: a warning, or silent where its text is a case's. */
    public function testALiteralOfAnotherTypeIsAWarning(): void
    {
        assertSame(['warning ARITY_MISMATCH/enum_case@15 reroute takes a Leg as argument 1, and 3 is none of its cases (with)'], self::judge(['cases' => self::LEG, 'backing' => 'string'], [self::int('3')]));
        assertSame(["warning ARITY_MISMATCH/enum_case@15 reroute takes a Leg as argument 1, and 'x' is none of its cases (with)"], self::judge(['cases' => [['name' => 'Low', 'value' => '1']], 'backing' => 'int'], [self::string('x')]));
        assertSame([], self::judge(['cases' => [['name' => 'Low', 'value' => '1']], 'backing' => 'int'], [self::string('1')]));
    }

    /** Only a parameter typed by the enum alone is judged: a nullable or union type, a class, a rest parameter or no parameter at all is not. */
    public function testOnlyAParameterOfTheEnumAloneIsJudged(): void
    {
        $backed = ['cases' => self::LEG, 'backing' => 'string'];
        $rail = [self::string('rail')];

        assertSame([], self::judge($backed, $rail, '?App\Leg'));
        assertSame([], self::judge($backed, $rail, 'App\Leg|string'));
        assertSame([], self::judge($backed, $rail, 'App\Other'));
        assertSame([], self::judge($backed, $rail, enumKind: 'class'), 'A class carrying cases is no PHP enum.');
        assertSame([], self::judge($backed, [self::string('air'), self::string('rail')]), 'The second argument goes to a rest parameter of strings.');
        assertSame(["definite ARITY_MISMATCH/enum_case@15 reroute takes a Leg as argument 1, and 'rail' is none of its cases (with)"], self::judge($backed, $rail, '\App\Leg'));
    }

    public function testASpreadOrNegatedMatcherIsSilent(): void
    {
        $backed = ['cases' => self::LEG, 'backing' => 'string'];

        assertSame([], self::judge($backed, [self::string('rail')], matcher: ['spread' => true]));
        assertSame([], self::judge($backed, [self::string('rail')], matcher: ['negated' => true]));
    }

    /** Only the PHP worker sends a matcher's literals; another language's enum is never looked up. */
    public function testAnotherLanguageIsSilent(): void
    {
        $enum = DriftFixtures::node('enum', 'src/leg.ts#Leg', 'Leg', ['cases' => self::LEG, 'backing' => 'string']);
        $graph = DriftFixtures::graph(
            [DriftFixtures::method('reroute', ['signature' => ['params' => [['name' => 'to', 'type' => 'Leg']]]])],
            [DriftFixtures::stub('reroute', null, [], [['api' => 'toHaveBeenCalledWith', 'line' => 9, 'count' => 1, 'spread' => false, 'literals' => [self::string('rail')]]])],
            extra: [$enum],
        );

        assertSame([], [...(new ArityMismatchRule())->findings($graph)]);
    }
}
