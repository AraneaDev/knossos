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
    public function testPrivateMemberIsDefinite(): void
    {
        $graph = DriftFixtures::graph([DriftFixtures::method('hash', ['visibility' => 'private'])], [DriftFixtures::stub('hash')]);

        $findings = [...(new VisibilityBreachRule())->findings($graph)];

        assertCount(1, $findings);
        assertSame(FindingConfidence::Definite, $findings[0]->confidence);
        assertSame('hash is private; a double cannot replace it', $findings[0]->message);
    }

    public function testProtectedIsReportedAndPublicIsNot(): void
    {
        $graph = DriftFixtures::graph(
            [DriftFixtures::method('a', ['visibility' => 'protected']), DriftFixtures::method('b')],
            [DriftFixtures::stub('a'), DriftFixtures::stub('b')],
        );

        $findings = [...(new VisibilityBreachRule())->findings($graph)];

        assertCount(1, $findings);
        assertSame('a is protected; a double cannot replace it', $findings[0]->message);
    }
}
