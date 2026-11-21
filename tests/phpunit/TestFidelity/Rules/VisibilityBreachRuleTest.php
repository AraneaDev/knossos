<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity\Rules;

use Knossos\TestFidelity\FindingConfidence;
use Knossos\TestFidelity\Rules\VisibilityBreachRule;
use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

#[Group('test-fidelity')]
final class VisibilityBreachRuleTest extends KnossosTestCase
{
    public function testATypeScriptPrivateMemberIsAWarning(): void
    {
        // `private` holds at compile time only: `vi.spyOn(s as any, 'hash')` replaces it at run time.
        $graph = DriftFixtures::graph([DriftFixtures::method('hash', ['visibility' => 'private'])], [DriftFixtures::stub('hash')]);

        $findings = [...(new VisibilityBreachRule())->findings($graph)];

        assertCount(1, $findings);
        assertSame(FindingConfidence::Warning, $findings[0]->confidence);
        assertSame('hash is private; the double bypasses the public interface', $findings[0]->message);
        assertSame(['member' => 'hash', 'visibility' => 'private'], $findings[0]->attributes);
    }

    public function testProtectedIsAWarningAndPublicIsNotReported(): void
    {
        $graph = DriftFixtures::graph(
            [DriftFixtures::method('a', ['visibility' => 'protected']), DriftFixtures::method('b')],
            [DriftFixtures::stub('a'), DriftFixtures::stub('b')],
        );

        $findings = [...(new VisibilityBreachRule())->findings($graph)];

        assertCount(1, $findings);
        assertSame(FindingConfidence::Warning, $findings[0]->confidence);
        assertSame('a is protected; the double bypasses the public interface', $findings[0]->message);
    }

    public function testAnEcmaScriptPrivateMemberIsDefinite(): void
    {
        // A `#name` member is no property: no double can reach it.
        $graph = DriftFixtures::graph([DriftFixtures::method('#hash')], [DriftFixtures::stub('#hash')]);

        $findings = [...(new VisibilityBreachRule())->findings($graph)];

        assertCount(1, $findings);
        assertSame(FindingConfidence::Definite, $findings[0]->confidence);
        assertSame('#hash is private; a double cannot replace it', $findings[0]->message);
        assertSame(['member' => '#hash', 'visibility' => 'private'], $findings[0]->attributes);
    }

    public function testAnEcmaScriptPrivateNameTheMemberListHoldsIsDefinite(): void
    {
        // The worker lists a `#name` member without a node of its own.
        $graph = DriftFixtures::graph([], [DriftFixtures::stub('#hash')], targetAttributes: ['member_names' => ['#hash']]);

        $findings = [...(new VisibilityBreachRule())->findings($graph)];

        assertSame([[FindingConfidence::Definite, '#hash is private; a double cannot replace it']], array_map(static fn($finding): array => [$finding->confidence, $finding->message], $findings));
    }

    public function testAStubbedNameOfNoMemberIsNoBreach(): void
    {
        assertSame([], [...(new VisibilityBreachRule())->findings(DriftFixtures::graph([], [DriftFixtures::stub('#gone')]))]);
    }
    /**
     * The rule's verdicts on a PHP double stubbing a method of each visibility.
     *
     * @param array<string, mixed> $double overrides of the double's attributes
     * @return list<array{string, FindingConfidence}>
     */
    private static function judgePhp(array $double = []): array
    {
        $graph = DriftFixtures::phpGraph(
            [['hash', ['visibility' => 'private']], ['load', ['visibility' => 'protected']], ['find', ['visibility' => 'public']]],
            [DriftFixtures::stub('hash'), DriftFixtures::stub('load'), DriftFixtures::stub('find')],
            $double,
        );

        return array_map(static fn($finding): array => [$finding->message, $finding->confidence], [...(new VisibilityBreachRule())->findings($graph)]);
    }

    public function testPhpPrivateAndProtectedAreDefinite(): void
    {
        // PHP visibility holds at run time: a double cannot replace a private method, nor a protected one unless it opted in.
        assertSame([
            ['hash is private; the double bypasses the public interface', FindingConfidence::Definite],
            ['load is protected; the double bypasses the public interface', FindingConfidence::Definite],
        ], self::judgePhp());
    }

    public function testPhpProtectedWithAllowsProtectedIsSilent(): void
    {
        $private = [['hash is private; the double bypasses the public interface', FindingConfidence::Definite]];

        assertSame($private, self::judgePhp(['allows_protected' => true]), 'shouldAllowMockingProtectedMethods() reaches protected methods, never private ones.');
        assertSame($private, self::judgePhp(['pattern' => 'getMockBuilder']), 'A getMockBuilder() double reaches protected methods.');
    }
}
