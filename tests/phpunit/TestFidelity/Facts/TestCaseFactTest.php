<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity\Facts;

use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use Knossos\TestFidelity\Facts\TestCaseFact;
use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

#[Group('test-fidelity')]
final class TestCaseFactTest extends KnossosTestCase
{
    /**
     * @param array<string, mixed> $attributes
     */
    private static function node(string $kind, array $attributes): NodeFact
    {
        return new NodeFact(
            'test:1',
            $kind,
            'src/a.test.ts#test:3',
            'test',
            Origin::Ast,
            Confidence::Certain,
            new Evidence('src/a.test.ts', 3, 12),
            $attributes,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function attributes(): array
    {
        return [
            'name' => 'totals the cart',
            'production_calls' => 1,
            'subjects' => ['src/cart.ts'],
            'assertions' => [[
                'api' => 'toBe',
                'line' => 9,
                'operands' => [
                    ['source' => 'mock-config', 'text' => 'cart.total()', 'value' => '42'],
                    ['source' => 'literal', 'text' => '42'],
                ],
            ]],
        ];
    }

    public function testReadsOperandSources(): void
    {
        $case = TestCaseFact::fromNode(self::node('test_case', self::attributes()));

        assertSame('mock-config', $case?->assertions[0]->operands[0]->source);
        assertSame('literal', $case->assertions[0]->operands[1]->source);
        assertSame('42', $case->assertions[0]->operands[1]->text);
        assertSame(false, $case->assertions[0]->operands[1]->unconfiguredSpy);
        assertSame('cart.total()', $case->assertions[0]->operands[0]->text);
        assertSame('42', $case->assertions[0]->operands[0]->value);
        assertNull($case->assertions[0]->operands[1]->value);
        assertSame(['src/cart.ts'], $case->subjects);
        assertNull($case->shouldPanic);
    }

    public function testReturnsNullForAnUnknownOperandSource(): void
    {
        $attributes = self::attributes();
        $attributes['assertions'][0]['operands'][0]['source'] = 'telepathy';

        assertNull(TestCaseFact::fromNode(self::node('test_case', $attributes)));
    }

    public function testReturnsNullForAValueThatIsNoString(): void
    {
        $attributes = self::attributes();
        $attributes['assertions'][0]['operands'][0]['value'] = 42;

        assertNull(TestCaseFact::fromNode(self::node('test_case', $attributes)));
    }

    public function testReturnsNullForMalformedAssertionsAndOtherKinds(): void
    {
        assertNull(TestCaseFact::fromNode(self::node('test_case', ['assertions' => 'x'] + self::attributes())));
        assertNull(TestCaseFact::fromNode(self::node('function', self::attributes())));
    }
}
