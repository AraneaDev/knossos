<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity\Rules;

use Knossos\TestFidelity\FindingConfidence;
use Knossos\TestFidelity\Rules\ReturnDriftRule;
use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

#[Group('test-fidelity')]
final class ReturnDriftRuleTest extends KnossosTestCase
{
    /** @return list<\Knossos\TestFidelity\TestFinding> */
    private static function judge(bool|string $assignable, bool $typed, ?string $returns = 'number'): array
    {
        $signature = ['params' => []] + ($returns === null ? [] : ['returns' => $returns]);
        $graph = DriftFixtures::graph(
            [DriftFixtures::method('total', ['signature' => $signature])],
            [DriftFixtures::stub('total', null, [['api' => 'mockReturnValue', 'line' => 9, 'once' => false, 'assignable' => $assignable, 'typed' => $typed]])],
        );

        return [...(new ReturnDriftRule())->findings($graph)];
    }

    public function testAssignableFalseAndTypedIsDefinite(): void
    {
        $findings = self::judge(false, true);

        assertCount(1, $findings);
        assertSame(FindingConfidence::Definite, $findings[0]->confidence);
        assertSame('total returns number; the double pins a value it does not admit (mockReturnValue)', $findings[0]->message);
        assertSame(9, $findings[0]->evidence->startLine);
    }

    public function testAssignableUnknownOrTrueIsSilent(): void
    {
        assertSame([], self::judge('unknown', true));
        assertSame([], self::judge(true, true));
    }

    public function testAnOverloadedMemberIsSilent(): void
    {
        $graph = DriftFixtures::graph(
            [DriftFixtures::method('total', ['signature' => ['params' => [], 'returns' => 'number'], 'overloaded' => true])],
            [DriftFixtures::stub('total', null, [['api' => 'mockReturnValue', 'line' => 9, 'once' => false, 'assignable' => false, 'typed' => true]])],
        );

        assertSame([], [...(new ReturnDriftRule())->findings($graph)]);
    }

    public function testATruncatedOrMissingSignatureIsSilent(): void
    {
        $pinned = [['api' => 'mockReturnValue', 'line' => 9, 'once' => false, 'assignable' => false, 'typed' => false]];
        foreach ([['signature' => ['params' => [], 'truncated' => true]], []] as $attributes) {
            $graph = DriftFixtures::graph([DriftFixtures::method('total', $attributes)], [DriftFixtures::stub('total', null, $pinned)]);

            assertSame([], [...(new ReturnDriftRule())->findings($graph)]);
        }
    }

    public function testUntypedIsAWarning(): void
    {
        assertSame(FindingConfidence::Warning, self::judge(false, false, null)[0]->confidence);
        assertSame('total returns an inferred type; the double pins a value it does not admit (mockReturnValue)', self::judge(false, false, null)[0]->message);
    }
    /**
     * A PHP stub of `App\UserService::total()` pinned to one value of `$type`, as the PHP worker emits it.
     *
     * @return list<\Knossos\TestFidelity\TestFinding>
     */
    private static function judgePhp(string $type, ?string $returns, bool $typed = true): array
    {
        $signature = ['params' => []] + ($returns === null ? [] : ['returns' => $returns]);
        $graph = DriftFixtures::phpGraph(
            [['total', ['signature' => $signature, 'visibility' => 'public']], ['make', ['signature' => ['params' => [], 'returns' => 'self']]]],
            [DriftFixtures::stub('total', null, [['api' => 'willReturn', 'line' => 9, 'once' => false, 'assignable' => 'unknown', 'typed' => $typed, 'type' => $type]])],
        );

        return [...(new ReturnDriftRule())->findings($graph)];
    }

    public function testPhpStringPinOnIntReturnIsDefinite(): void
    {
        $findings = self::judgePhp('string', 'int');

        assertCount(1, $findings);
        assertSame(FindingConfidence::Definite, $findings[0]->confidence);
        assertSame('total returns int; the double pins a value it does not admit (willReturn)', $findings[0]->message);
        assertSame(9, $findings[0]->evidence->startLine);
    }

    public function testAPhpPinThatFitsOrCannotBeJudgedIsSilent(): void
    {
        assertSame([], self::judgePhp('int', 'float'));
        assertSame([], self::judgePhp('null', '?int'));
        assertSame([], self::judgePhp('string', 'mixed'));
        assertSame([], self::judgePhp('string', null), 'No declared return type: nothing to judge against.');
        assertSame([], self::judgePhp('unknown', 'int', false));
        assertSame([], self::judgePhp('resource', 'int'), 'A type outside the list is unknown.');
    }

    public function testAPhpPinOnAVendorTypeIsAWarning(): void
    {
        $findings = self::judgePhp('class:App\\UserService', 'Psr\\Log\\LoggerInterface');

        assertCount(1, $findings);
        assertSame(FindingConfidence::Warning, $findings[0]->confidence);
    }

    public function testSelfInAPhpReturnTypeIsTheDeclaringClass(): void
    {
        $pin = static fn(string $type): array => [DriftFixtures::stub('make', null, [['api' => 'willReturn', 'line' => 9, 'once' => false, 'assignable' => 'unknown', 'typed' => true, 'type' => $type]])];
        $methods = [['make', ['signature' => ['params' => [], 'returns' => 'self']]]];

        assertSame([], [...(new ReturnDriftRule())->findings(DriftFixtures::phpGraph($methods, $pin('class:App\\UserService')))]);
        assertCount(1, [...(new ReturnDriftRule())->findings(DriftFixtures::phpGraph($methods, $pin('int')))]);
    }

    public function testATypeScriptPinKeepsTheWorkersVerdict(): void
    {
        $graph = DriftFixtures::graph(
            [DriftFixtures::method('total', ['signature' => ['params' => [], 'returns' => 'number']])],
            [DriftFixtures::stub('total', null, [['api' => 'mockReturnValue', 'line' => 9, 'once' => false, 'assignable' => 'unknown', 'typed' => true, 'type' => 'string']])],
        );

        assertSame([], [...(new ReturnDriftRule())->findings($graph)]);
    }
}
