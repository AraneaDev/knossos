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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('test-fidelity')]
final class TestDoubleTest extends KnossosTestCase
{
    /** The keys the contribution schema requires of a test_double, a stubbedMember and a callMatcher. */
    private const DOUBLE_KEYS = ['framework', 'pattern', 'target', 'automock', 'invocations', 'members'];
    private const MEMBER_KEYS = ['name', 'line', 'api', 'returns'];
    private const MATCHER_KEYS = ['api', 'line', 'count', 'spread'];

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

    public function testReadsRustFrameworksAndApis(): void
    {
        $attributes = self::attributes(['framework' => 'mockall', 'pattern' => 'mockall::automock']);
        $attributes['members'][0]['api'] = 'expect';
        $fake = self::attributes(['framework' => 'hand-written', 'pattern' => 'impl']);
        $fake['members'][0]['api'] = 'impl';

        $double = TestDouble::fromNode(self::node('test_double', $attributes));
        $other = TestDouble::fromNode(self::node('test_double', $fake));

        assertSame(['mockall', 'mockall::automock', 'expect'], [$double?->framework, $double?->pattern, $double?->members[0]->api]);
        assertSame(['hand-written', 'impl'], [$other?->framework, $other?->members[0]->api]);
        foreach (['expect', 'context', 'when', 'mock', 'mock_fn', 'given', 'impl'] as $api) {
            $attributes['members'][0]['api'] = $api;
            assertSame($api, TestDouble::fromNode(self::node('test_double', $attributes))?->members[0]->api);
        }
        foreach (['faux', 'mry', 'mockers', 'mockiato', 'mocktopus', 'mock_derive', 'galvanic'] as $framework) {
            assertSame($framework, TestDouble::fromNode(self::node('test_double', self::attributes(['framework' => $framework])))?->framework);
        }
    }

    public function testReadsCompileChecked(): void
    {
        $read = static fn(array $overrides): ?bool => TestDouble::fromNode(self::node('test_double', self::attributes($overrides)))?->compileChecked;

        assertSame(true, $read(['compile_checked' => true]));
        assertSame(false, $read([]));
        assertSame(false, $read(['compile_checked' => 'yes']));
        assertSame(false, $read(['compile_checked' => 1]));
    }

    public function testReadsWhetherAMatcherIsNegated(): void
    {
        // G3-02: a matcher read through `.not`; a flag that is no boolean, or a keyword list that cannot bind, drops the matcher and keeps the double.
        $attributes = self::attributes();
        $attributes['members'][0]['called_with'] = [
            ['api' => 'toHaveBeenCalledWith', 'line' => 29, 'count' => 0, 'spread' => false, 'negated' => true],
            ['api' => 'toHaveBeenCalledWith', 'line' => 30, 'count' => 1, 'spread' => false],
            ['api' => 'toHaveBeenCalledWith', 'line' => 31, 'count' => 1, 'spread' => false, 'negated' => 'yes'],
            ['api' => 'assert_called_with', 'line' => 32, 'count' => 1, 'spread' => false, 'keywords' => [3], 'negated' => true],
            ['api' => 'assert_called_with', 'line' => 33, 'count' => 1, 'spread' => false, 'keywords' => ['a'], 'negated' => true],
        ];

        $matchers = TestDouble::fromNode(self::node('test_double', $attributes))?->members[0]->calledWith ?? [];

        assertSame([[29, true, false], [30, false, false], [33, true, false]], array_map(static fn($matcher): array => [$matcher->line, $matcher->negated, $matcher->spread], $matchers));
    }

    public function testReadsMatcherKeywords(): void
    {
        $attributes = self::attributes();
        $attributes['members'][0]['called_with'] = [['api' => 'assert_called_with', 'line' => 29, 'count' => 2, 'spread' => false, 'keywords' => ['user', 'token']]];

        $double = TestDouble::fromNode(self::node('test_double', $attributes));

        assertSame(['user', 'token'], $double?->members[0]->calledWith[0]->keywords);
        assertSame(false, $double->members[0]->calledWith[0]->spread);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unbindableKeywords(): array
    {
        return [
            'null' => [null],
            'not an array' => ['user'],
            'not a list' => [['a' => 'user']],
            'a non-string name' => [[3]],
            'an empty name' => [['']],
            'more keywords than arguments' => [['user', 'token']],
        ];
    }

    #[DataProvider('unbindableKeywords')]
    public function testAKeywordListThatCannotBindRejectsTheMatcherNotTheDouble(mixed $keywords): void
    {
        $attributes = self::attributes();
        $attributes['members'][0]['called_with'] = [
            ['api' => 'assert_called_with', 'line' => 29, 'count' => 1, 'spread' => false, 'keywords' => $keywords],
            ['api' => 'assert_called_with', 'line' => 30, 'count' => 1, 'spread' => false, 'keywords' => ['user']],
        ];

        $double = TestDouble::fromNode(self::node('test_double', $attributes));

        assertSame([30], array_map(static fn($matcher): int => $matcher->line, $double?->members[0]->calledWith ?? []));
    }

    public function testAKeywordCountEqualToTheArgumentCountIsKept(): void
    {
        $attributes = self::attributes();
        $attributes['members'][0]['called_with'] = [['api' => 'assert_called_with', 'line' => 29, 'count' => 2, 'spread' => false, 'keywords' => ['user', 'token']]];

        assertSame(['user', 'token'], TestDouble::fromNode(self::node('test_double', $attributes))?->members[0]->calledWith[0]->keywords);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function requiredDoubleKeys(): array
    {
        return array_combine(self::DOUBLE_KEYS, array_map(static fn(string $key): array => [$key], self::DOUBLE_KEYS));
    }

    #[DataProvider('requiredDoubleKeys')]
    public function testADoubleMissingASchemaRequiredKeyIsNoFact(string $key): void
    {
        $attributes = self::attributes();
        unset($attributes[$key]);

        assertNull(TestDouble::fromNode(self::node('test_double', $attributes)));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function requiredMemberKeys(): array
    {
        return array_combine(self::MEMBER_KEYS, array_map(static fn(string $key): array => [$key], self::MEMBER_KEYS));
    }

    #[DataProvider('requiredMemberKeys')]
    public function testAMemberMissingASchemaRequiredKeyMakesTheDoubleNoFact(string $key): void
    {
        $attributes = self::attributes();
        unset($attributes['members'][0][$key]);

        assertNull(TestDouble::fromNode(self::node('test_double', $attributes)));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function requiredMatcherKeys(): array
    {
        return array_combine(self::MATCHER_KEYS, array_map(static fn(string $key): array => [$key], self::MATCHER_KEYS));
    }

    #[DataProvider('requiredMatcherKeys')]
    public function testAMatcherMissingASchemaRequiredKeyIsDropped(string $key): void
    {
        $matcher = ['api' => 'toHaveBeenCalledWith', 'line' => 29, 'count' => 1, 'spread' => false];
        unset($matcher[$key]);
        $attributes = self::attributes();
        $attributes['members'][0]['called_with'] = [$matcher];

        assertSame([], TestDouble::fromNode(self::node('test_double', $attributes))?->members[0]->calledWith);
    }

    public function testAMemberOnALineBelowOneIsNoFact(): void
    {
        foreach ([0, -1] as $line) {
            $attributes = self::attributes();
            $attributes['members'][0]['line'] = $line;

            assertNull(TestDouble::fromNode(self::node('test_double', $attributes)), (string) $line);
        }
        $attributes = self::attributes();
        $attributes['members'][0]['line'] = 1;
        assertSame(1, TestDouble::fromNode(self::node('test_double', $attributes))?->members[0]->line);
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

    /** What a built-in pinned class is, as the worker reflected it (G3-10); a malformed list is none, and the pin stays. */
    public function testReadsWhatABuiltInPinIs(): void
    {
        $read = static function (mixed $ancestors): ?array {
            $attributes = self::attributes();
            $attributes['members'][0]['returns'][0]['type'] = 'class:DateTimeImmutable';
            $attributes['members'][0]['returns'][0]['type_ancestors'] = $ancestors;

            return TestDouble::fromNode(self::node('test_double', $attributes))?->members[0]->returns[0]->typeAncestors;
        };

        assertSame(['DateTimeInterface'], $read(['DateTimeInterface']));
        assertSame([], $read('DateTimeInterface'));
        assertSame([], $read(['DateTimeInterface', 3]));
        assertSame([], $read(['']));
        assertSame([], $read(['a' => 'DateTimeInterface']));
        assertSame([], TestDouble::fromNode(self::node('test_double', self::attributes()))?->members[0]->returns[0]->typeAncestors);
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
