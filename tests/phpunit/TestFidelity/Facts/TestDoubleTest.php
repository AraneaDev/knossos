<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity\Facts;

use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use Knossos\TestFidelity\Facts\TestDouble;
use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

#[Group('test-fidelity')]
final class TestDoubleTest extends KnossosTestCase
{
    /**
     * @param array<string, mixed> $attributes
     */
    private static function node(string $kind, array $attributes): NodeFact
    {
        return new NodeFact(
            'double:1',
            $kind,
            'src/a.test.ts#double:4:2',
            'double',
            Origin::Ast,
            Confidence::Certain,
            new Evidence('src/a.test.ts', 4, 4),
            $attributes,
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function attributes(array $overrides = []): array
    {
        return $overrides + [
            'framework' => 'vitest',
            'pattern' => 'vi.spyOn',
            'target' => ['kind' => 'instance-member', 'member' => 'totalForX'],
            'automock' => false,
            'invocations' => 2,
            'members' => [[
                'name' => 'totalForX',
                'line' => 8,
                'api' => 'spyOn',
                'returns' => [['api' => 'mockReturnValue', 'line' => 9, 'once' => false, 'assignable' => false, 'typed' => true]],
            ]],
        ];
    }

    public function testReadsASpyWithOneMember(): void
    {
        $double = TestDouble::fromNode(self::node('test_double', self::attributes()));

        assertSame('totalForX', $double?->members[0]->name);
        assertSame(false, $double->members[0]->returns[0]->assignable);
        assertSame('vitest', $double->framework);
        assertSame(['kind' => 'instance-member', 'member' => 'totalForX'], $double->target);
        assertSame(2, $double->invocations);
        assertNull($double->testCase);
    }

    public function testReadsAnUnknownAssignabilityAndATestCaseLink(): void
    {
        $attributes = self::attributes(['test_case' => 'src/a.test.ts#test:3']);
        $attributes['members'][0]['returns'][0]['assignable'] = 'unknown';

        $double = TestDouble::fromNode(self::node('test_double', $attributes));

        assertSame('unknown', $double?->members[0]->returns[0]->assignable);
        assertSame('src/a.test.ts#test:3', $double->testCase);
    }

    public function testReturnsNullForMalformedMembers(): void
    {
        assertNull(TestDouble::fromNode(self::node('test_double', self::attributes(['members' => 'x']))));
    }

    public function testReturnsNullForAMalformedReturnOrAMissingTarget(): void
    {
        $badReturn = self::attributes();
        $badReturn['members'][0]['returns'][0]['assignable'] = 'maybe';
        $noTarget = self::attributes();
        unset($noTarget['target']);

        assertNull(TestDouble::fromNode(self::node('test_double', $badReturn)));
        assertNull(TestDouble::fromNode(self::node('test_double', $noTarget)));
        assertNull(TestDouble::fromNode(self::node('test_double', self::attributes(['framework' => 'mocha']))));
    }

    public function testReadsCallMatchers(): void
    {
        $attributes = self::attributes();
        $attributes['members'][0]['called_with'] = [['api' => 'toHaveBeenCalledWith', 'line' => 29, 'count' => 3, 'spread' => false]];

        $double = TestDouble::fromNode(self::node('test_double', $attributes));

        assertSame(3, $double?->members[0]->calledWith[0]->count);
        assertSame('toHaveBeenCalledWith', $double->members[0]->calledWith[0]->api);
        assertSame(29, $double->members[0]->calledWith[0]->line);
        assertSame(false, $double->members[0]->calledWith[0]->spread);
    }

    public function testDropsAMalformedCallMatcherAndKeepsTheDouble(): void
    {
        $attributes = self::attributes();
        $attributes['members'][0]['called_with'] = [
            ['api' => 'toHaveBeenCalledWith', 'line' => 29, 'count' => -1, 'spread' => false],
            ['api' => 'toHaveBeenCalledWith', 'line' => 30, 'count' => 1, 'spread' => 'no'],
            'x',
            ['api' => 'toHaveBeenCalledWith', 'line' => 31, 'count' => 2, 'spread' => true],
        ];
        $notAList = self::attributes();
        $notAList['members'][0]['called_with'] = 'x';

        $double = TestDouble::fromNode(self::node('test_double', $attributes));
        $other = TestDouble::fromNode(self::node('test_double', $notAList));

        assertSame(1, count($double?->members[0]->calledWith ?? []));
        assertSame(31, $double->members[0]->calledWith[0]->line);
        assertSame('totalForX', $double->members[0]->name);
        assertSame([], $other?->members[0]->calledWith);
    }

    public function testReadsPythonFrameworksAndApis(): void
    {
        $attributes = self::attributes(['framework' => 'pytest-mock', 'pattern' => 'pytest_mock.mocker.patch.object']);
        $attributes['members'][0]['api'] = 'patch.object';
        $apis = [];
        foreach (['unittest', 'pytest'] as $framework) {
            $apis[$framework] = TestDouble::fromNode(self::node('test_double', self::attributes(['framework' => $framework])))?->framework;
        }

        $double = TestDouble::fromNode(self::node('test_double', $attributes));

        assertSame('pytest-mock', $double?->framework);
        assertSame('patch.object', $double->members[0]->api);
        assertSame(['unittest' => 'unittest', 'pytest' => 'pytest'], $apis);
    }

    public function testReadsMatcherKeywords(): void
    {
        $attributes = self::attributes();
        $attributes['members'][0]['called_with'] = [['api' => 'assert_called_with', 'line' => 29, 'count' => 2, 'spread' => false, 'keywords' => ['user', 'token']]];

        $double = TestDouble::fromNode(self::node('test_double', $attributes));

        assertSame(['user', 'token'], $double?->members[0]->calledWith[0]->keywords);
        assertSame(false, $double->members[0]->calledWith[0]->spread);
    }

    public function testAMalformedKeywordListMakesTheMatcherSpread(): void
    {
        $attributes = self::attributes();
        $attributes['members'][0]['called_with'] = [
            ['api' => 'assert_called_with', 'line' => 29, 'count' => 1, 'spread' => false, 'keywords' => [3]],
            ['api' => 'assert_called_with', 'line' => 30, 'count' => 1, 'spread' => false, 'keywords' => 'user'],
        ];

        $double = TestDouble::fromNode(self::node('test_double', $attributes));

        assertSame(2, count($double?->members[0]->calledWith ?? []));
        assertSame(true, $double->members[0]->calledWith[0]->spread);
        assertSame([], $double->members[0]->calledWith[0]->keywords);
        assertSame(true, $double->members[0]->calledWith[1]->spread);
    }

    public function testReadsAPinnedType(): void
    {
        $attributes = self::attributes();
        $attributes['members'][0]['returns'][0]['type'] = 'string';
        $malformed = self::attributes();
        $malformed['members'][0]['returns'][0]['type'] = 5;

        $double = TestDouble::fromNode(self::node('test_double', $attributes));
        $other = TestDouble::fromNode(self::node('test_double', $malformed));

        assertSame('string', $double?->members[0]->returns[0]->type);
        assertNull($other?->members[0]->returns[0]->type);
        assertSame(false, $other->members[0]->returns[0]->assignable);
    }

    public function testReadsAllowsProtectedAndFrameworksOfPhase3(): void
    {
        $double = TestDouble::fromNode(self::node('test_double', self::attributes(['framework' => 'mockery', 'allows_protected' => true])));
        $plain = TestDouble::fromNode(self::node('test_double', self::attributes(['framework' => 'pest'])));
        $bad = TestDouble::fromNode(self::node('test_double', self::attributes(['framework' => 'prophecy', 'allows_protected' => 'yes'])));

        assertSame(true, $double?->allowsProtected);
        assertSame(false, $plain?->allowsProtected);
        assertSame(false, $bad?->allowsProtected);
        assertSame('phpunit', TestDouble::fromNode(self::node('test_double', self::attributes(['framework' => 'phpunit'])))?->framework);
    }

    public function testReturnsNullForOtherKinds(): void
    {
        assertNull(TestDouble::fromNode(self::node('class', self::attributes())));
    }
}
