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

    public function testUntypedIsAWarning(): void
    {
        assertSame(FindingConfidence::Warning, self::judge(false, false, null)[0]->confidence);
        assertSame('total returns an inferred type; the double pins a value it does not admit (mockReturnValue)', self::judge(false, false, null)[0]->message);
    }
}
