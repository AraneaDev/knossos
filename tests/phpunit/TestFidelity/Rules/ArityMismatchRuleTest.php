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
