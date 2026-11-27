<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity\Rules;

use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\EdgeFact;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use Knossos\TestFidelity\FindingConfidence;
use Knossos\TestFidelity\Rules\ArityMismatchRule;
use Knossos\TestFidelity\TestFidelityGraph;
use Knossos\TestFidelity\TestFinding;
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

    public function testACompileCheckedDoubleIsNeverJudged(): void
    {
        $methods = [DriftFixtures::method('find', ['signature' => ['params' => [['name' => 'id', 'type' => 'string']]]])];
        $members = [DriftFixtures::stub('find', [['name' => 'id', 'type' => 'string'], ['name' => 'extra', 'type' => 'number']], [], [self::matcher(3)])];

        assertCount(1, [...(new ArityMismatchRule())->findings(DriftFixtures::graph($methods, $members))], 'the control');
        assertSame([], [...(new ArityMismatchRule())->findings(DriftFixtures::graph($methods, $members, doubleAttributes: ['compile_checked' => true]))]);
    }

    public function testTooFewIsDefinite(): void
    {
        $findings = self::judgeCalls(['params' => [['name' => 'id'], ['name' => 'patch'], ['name' => 'deep', 'optional' => true]]], [self::matcher(1)]);

        assertCount(1, $findings);
        assertSame(FindingConfidence::Definite, $findings[0]->confidence);
        assertSame('call_arity', $findings[0]->subRule);
        assertSame('find needs 2 argument(s); the matcher expects 1', $findings[0]->message);
    }

    public function testTooFewUnderNotIsNoArityDrift(): void
    {
        // G3-02: `.not.toHaveBeenCalledWith()` for `rate(x)` cannot fail; TautologyRule reports it, as vacuous.
        $signature = ['params' => [['name' => 'id'], ['name' => 'patch']]];
        $negated = static fn(int $count): array => ['negated' => true] + self::matcher($count);

        assertSame([], self::judgeCalls($signature, [$negated(1)]));
        assertSame([], self::judgeCalls($signature, [$negated(0), self::matcher(2)]));
        assertSame(['find takes 2 argument(s); the matcher expects 3'], array_map(static fn($finding): string => $finding->message, self::judgeCalls($signature, [$negated(3)])), 'too many under .not is still drift');
        assertSame(['find needs 2 argument(s); the matcher expects 1'], array_map(static fn($finding): string => $finding->message, self::judgeCalls($signature, [$negated(1), self::matcher(1)])), 'the control');
    }

    public function testAMatcherWithAMalformedNegationIsDropped(): void
    {
        assertSame([], self::judgeCalls(['params' => [['name' => 'id'], ['name' => 'patch']]], [['negated' => 'yes'] + self::matcher(1)]));
    }

    public function testAnOverloadedMemberIsSilentForAMatcher(): void
    {
        // G3-02: the node holds the first signature only, so no count can be held against it.
        $graph = DriftFixtures::graph(
            [DriftFixtures::method('find', ['signature' => ['params' => [['name' => 'id', 'type' => 'string']]], 'overloaded' => true])],
            [DriftFixtures::stub('find', null, [], [self::matcher(3), self::matcher(0)])],
        );

        assertSame([], [...(new ArityMismatchRule())->findings($graph)]);
    }

    public function testAProphecyMatcherIsExact(): void
    {
        // G3-02: only PHPUnit's `with()` leaves the arguments it is not given open.
        $signature = ['signature' => ['params' => [['name' => 'id', 'type' => 'string'], ['name' => 'deep', 'type' => 'bool']]]];
        $findings = static fn(string $framework, string $api): array => array_map(
            static fn($finding): string => $finding->message,
            [...(new ArityMismatchRule())->findings(DriftFixtures::graph([DriftFixtures::method('find', $signature)], [DriftFixtures::stub('find', null, [], [self::matcher(1, $api)])], framework: $framework))],
        );

        assertSame(['find needs 2 argument(s); the matcher expects 1'], $findings('prophecy', 'with'));
        assertSame([], $findings('phpunit', 'with'), 'the control');
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

    /**
     * Python matchers on `Session.open(username, token)` unless `$params` says otherwise.
     *
     * @param list<array<string, mixed>> $matchers
     * @param list<array<string, mixed>>|null $params
     * @param array<string, mixed> $attributes further attributes of the member node
     * @return list<TestFinding>
     */
    private static function python(array $matchers, ?array $params = null, array $attributes = []): array
    {
        $params ??= [['name' => 'username', 'type' => 'str'], ['name' => 'token', 'type' => 'str']];

        return self::findings(PythonFixtures::graph([['open', PythonFixtures::signature($params, 'str') + $attributes]], [PythonFixtures::stub('open', calledWith: $matchers)]));
    }

    /**
     * Each finding's confidence and message.
     *
     * @param list<TestFinding> $findings
     * @return list<string>
     */
    private static function messages(array $findings): array
    {
        return array_map(static fn(TestFinding $finding): string => $finding->confidence->value . ' ' . $finding->message, $findings);
    }

    public function testPythonUnknownKeywordIsDefinite(): void
    {
        // Nemesis `kwargs`: `user` was renamed to `username`; the test's keyword was not.
        $findings = self::python([PythonFixtures::matcher(2, ['user', 'token'])]);

        assertSame(['definite open has no parameter user; the matcher passes it by name'], self::messages($findings));
        assertSame('call_arity', $findings[0]->subRule);
        assertSame([], self::python([PythonFixtures::matcher(2, ['username', 'token'])]));
        assertSame([], self::python([PythonFixtures::matcher(2, ['token'])]));
    }

    public function testPythonKeywordOnlyByPositionIsTooMany(): void
    {
        $params = [['name' => 'a'], ['name' => 'b', 'kind' => 'keyword_only']];

        assertSame(['definite open takes 1 argument(s); the matcher expects 2'], self::messages(self::python([PythonFixtures::matcher(2)], $params)));
        assertSame([], self::python([PythonFixtures::matcher(2, ['b'])], $params));
        assertSame(['definite open takes 2 argument(s); the matcher expects 3'], self::messages(self::python([PythonFixtures::matcher(3)])));
    }

    public function testPythonPositionalOnlyByNameIsDefinite(): void
    {
        $params = [['name' => 'x', 'kind' => 'positional_only'], ['name' => 'y']];

        assertSame(['definite open has no parameter x; the matcher passes it by name'], self::messages(self::python([PythonFixtures::matcher(2, ['x', 'y'])], $params)));
        assertSame([], self::python([PythonFixtures::matcher(2, ['y'])], $params));
        // Under `**kwargs` the keyword lands there, and the positional-only parameter is left unbound.
        $open = [...$params, ['name' => '**kwargs', 'variadic' => true]];
        assertSame(['definite open needs 2 argument(s); the matcher expects 2'], self::messages(self::python([PythonFixtures::matcher(2, ['x', 'y'])], $open)));
    }

    public function testPythonRequiredParameterLeftUnboundIsDefinite(): void
    {
        assertSame(['definite open needs 2 argument(s); the matcher expects 1'], self::messages(self::python([PythonFixtures::matcher(1)])));
        assertSame(['definite open needs 2 argument(s); the matcher expects 1'], self::messages(self::python([PythonFixtures::matcher(1, ['token'])])));
        $keywordOnly = [['name' => 'a'], ['name' => 'b', 'kind' => 'keyword_only'], ['name' => 'c', 'optional' => true, 'kind' => 'keyword_only']];
        assertSame(['definite open needs 2 argument(s); the matcher expects 1'], self::messages(self::python([PythonFixtures::matcher(1)], $keywordOnly)));
        assertSame([], self::python([PythonFixtures::matcher(2, ['b'])], $keywordOnly));
    }

    public function testPythonKwargsAndArgsOpenTheirHalf(): void
    {
        $args = [['name' => 'a'], ['name' => '*args', 'variadic' => true], ['name' => 'c', 'kind' => 'keyword_only']];
        assertSame([], self::python([PythonFixtures::matcher(5, ['c'])], $args));
        assertSame(['definite open has no parameter z; the matcher passes it by name'], self::messages(self::python([PythonFixtures::matcher(3, ['c', 'z'])], $args)));
        assertSame(['definite open needs 2 argument(s); the matcher expects 3'], self::messages(self::python([PythonFixtures::matcher(3)], $args)));

        $kwargs = [['name' => 'a'], ['name' => '**kwargs', 'variadic' => true]];
        assertSame([], self::python([PythonFixtures::matcher(3, ['z', 'w'])], $kwargs));
        assertSame(['definite open takes 1 argument(s); the matcher expects 2'], self::messages(self::python([PythonFixtures::matcher(2)], $kwargs)));
    }

    public function testPythonSpreadMalformedAndDecoratedAreSilent(): void
    {
        // A spread, or a form that records the receiver (a class-level autospec, `mocker.spy` on a class), comes spread.
        assertSame([], self::python([PythonFixtures::matcher(5, [], true)]));
        // More keywords than arguments, or one named twice, is no call Python could make.
        assertSame([], self::python([PythonFixtures::matcher(1, ['user', 'token'])]));
        assertSame([], self::python([PythonFixtures::matcher(2, ['user', 'user'])]));
        assertSame([], self::python([PythonFixtures::matcher(3)], attributes: ['decorated' => true]));
        // The first matcher that cannot bind is the one reported.
        assertSame(['definite open has no parameter user; the matcher passes it by name'], self::messages(self::python([PythonFixtures::matcher(2), PythonFixtures::matcher(2, ['user', 'token'])])));
    }

    public function testPythonMethodsARewritableLineageMayReplaceAreSilent(): void
    {
        // Ruling P21: a project metaclass or `__init_subclass__` on the class or an ancestor may rewrite its methods.
        $judge = static function (array $baseAttributes, string $base = 'py:class:app.repo.Base', array $own = []): array {
            $owner = PythonFixtures::node('class', 'app.repo.Repo');
            $parent = PythonFixtures::node('class', 'app.repo.Base', ['member_names' => [], ...$baseAttributes]);
            $graph = PythonFixtures::graph(
                [['open', PythonFixtures::signature([['name' => 'username'], ['name' => 'token']])]],
                [PythonFixtures::stub('open', [PythonFixtures::pin('int')], [PythonFixtures::matcher(1)])],
                ownerAttributes: ['member_names' => ['open'], ...$own],
                extra: [$parent],
                extraEdges: [PythonFixtures::edge('extends', $owner, $base)],
            );

            return self::messages(self::findings($graph));
        };

        assertSame([], $judge(['methods_rewritable' => true]));
        assertSame([], $judge([], own: ['methods_rewritable' => true]));
        // A plain base, or one outside the project (G4-12), leaves the binding judged.
        assertSame(['definite open needs 2 argument(s); the matcher expects 1'], $judge([]));
        assertSame(['definite open needs 2 argument(s); the matcher expects 1'], $judge([], 'py:external_symbol:enum.Enum'));
        // The flag is Python's alone: another worker's class keeps its matchers judged.
        $typescript = DriftFixtures::graph(
            [DriftFixtures::method('find', ['signature' => ['params' => [['name' => 'id']]]])],
            [DriftFixtures::stub('find', null, [], [self::matcher(2)])],
            targetAttributes: ['member_names' => ['find'], 'methods_rewritable' => true],
        );
        assertCount(1, self::findings($typescript));
    }

    public function testPythonArityIsJudgedOnlyAgainstTheDefThatCertainlyAnswers(): void
    {
        // Ruling P24 (final review I1): Python's lookup, not a breadth-first walk, decides which def answers the name.
        $judge = static function (array $ownNames, array $parents, array $bases, array $own = []): array {
            $owner = PythonFixtures::node('class', 'app.repo.Repo');
            $edges = array_map(static fn(string $base): \Knossos\Scanner\Protocol\EdgeFact => PythonFixtures::edge('extends', $owner, $base), $parents);
            $extra = [];
            foreach ($bases as [$base, $names, $params, $baseParents]) {
                $node = PythonFixtures::node('class', 'app.repo.' . $base, ['member_names' => $names]);
                $extra[] = $node;
                if ($params !== null) {
                    $method = PythonFixtures::method($node, 'run', PythonFixtures::signature($params));
                    $extra[] = $method;
                    $edges[] = PythonFixtures::edge('contains', $node, $method->localId);
                }
                foreach ($baseParents as $parent) {
                    $edges[] = PythonFixtures::edge('extends', $node, $parent);
                }
            }
            $graph = PythonFixtures::graph($own, [PythonFixtures::stub('run', [], [PythonFixtures::matcher(1)])], ownerAttributes: ['member_names' => $ownNames], extra: $extra, extraEdges: $edges);

            return self::messages(self::findings($graph));
        };
        $one = [['name' => 'x']];
        $two = [['name' => 'x'], ['name' => 'y']];

        // `class Repo(B, C)`, `B(A)`: Python's MRO takes A's `run(x)`; breadth first takes C's `run(x, y)`.
        assertSame([], $judge(['run'], ['py:class:app.repo.B', 'py:class:app.repo.C'], [['B', ['run'], null, ['py:class:app.repo.A']], ['C', ['run'], $two, []], ['A', ['run'], $one, []]]));
        // `class Repo(logging.Handler, Base)`: the base outside the project comes first in the MRO.
        assertSame([], $judge([], ['py:external_symbol:logging.Handler', 'py:class:app.repo.Base'], [['Base', ['run'], $two, []]]));        // `class Repo(Base): run = _impl`, or `self.run = …`: the class's own binding shadows the inherited def.
        assertSame([], $judge(['run'], ['py:class:app.repo.Base'], [['Base', ['run'], $two, []]]));
        // A subclass that leaves the name to its one base is judged against that def.
        assertSame(['definite run needs 2 argument(s); the matcher expects 1'], $judge([], ['py:class:app.repo.Base'], [['Base', ['run'], $two, []]]));
        assertSame(['definite run needs 2 argument(s); the matcher expects 1'], $judge([], ['py:class:app.repo.B'], [['B', [], null, ['py:class:app.repo.Base']], ['Base', ['run'], $two, []]]));
        // A def on the class itself answers first, whatever its bases; under a base outside the project, which may rewrite it, only as a warning (U1, G4-12).
        assertSame(['warning run needs 2 argument(s); the matcher expects 1'], $judge(['run'], ['py:external_symbol:logging.Handler', 'py:class:app.repo.Base'], [['Base', ['other'], null, []]], [['run', PythonFixtures::signature($two)]]));
        assertSame(['definite run needs 2 argument(s); the matcher expects 1'], $judge(['run'], ['py:class:app.repo.Base'], [['Base', ['other'], null, []]], [['run', PythonFixtures::signature($two)]]));
    }

    public function testTypeScriptArityIgnoresWhoElseListsTheName(): void
    {
        // P24 is Python's: a TypeScript subclass that lists the name, under an unseen base, keeps the inherited signature judged.
        $base = DriftFixtures::node('class', 'src/UserService.ts#Base', 'Base', ['member_names' => ['find']]);
        $find = DriftFixtures::node('method', 'src/UserService.ts#Base::find', 'find', ['signature' => ['params' => [['name' => 'id']]]]);
        $class = DriftFixtures::node('class', 'src/UserService.ts#UserService', 'UserService');
        $graph = DriftFixtures::graph(
            [],
            [DriftFixtures::stub('find', null, [], [self::matcher(2)])],
            extra: [$base, $find],
            extraEdges: [DriftFixtures::edge('contains', $base, $find->localId), DriftFixtures::edge('extends', $class, 'ts:external_symbol:lib.Thing'), DriftFixtures::edge('extends', $class, $base->localId)],
            targetAttributes: ['member_names' => ['find']],
        );

        assertCount(1, self::findings($graph));
    }

    public function testPythonUntypedSideEffectLambdaIsAWarning(): void
    {
        $member = PythonFixtures::stub('open', params: [['name' => 'a'], ['name' => 'b'], ['name' => 'c']]);
        $untyped = self::findings(PythonFixtures::graph([['open', PythonFixtures::signature([['name' => 'username'], ['name' => 'token']])]], [$member]));

        assertSame(['warning open takes 2 argument(s); the double passes 3'], self::messages($untyped));
        assertSame('signature', $untyped[0]->subRule);
    }

    /**
     * A PHP method production hands on as a callable (`array_walk($xs, [$repo, 'save'], 7)`)
     * may be called with more arguments than it takes, so neither a matcher
     * nor a replacement listing more is held against it; fewer still is
     * (G3-31). Reached through a type of the target's lineage it counts, through
     * another class or for another name it does not.
     */
    public function testAPhpMethodReachedAsACallableMayTakeMoreArguments(): void
    {
        $save = ['signature' => ['params' => [['name' => 'name', 'type' => 'string'], ['name' => 'flags', 'type' => 'int']], 'returns' => 'bool']];
        $tooMany = ['name' => 'save', 'line' => 15, 'api' => 'method', 'returns' => [], 'called_with' => [['api' => 'with', 'line' => 15, 'count' => 3, 'spread' => false]]];
        $replacement = ['name' => 'save', 'line' => 15, 'api' => 'method', 'returns' => [], 'params' => [['name' => 'a', 'type' => 'string'], ['name' => 'b', 'type' => 'int'], ['name' => 'c', 'type' => 'int']]];
        $tooFew = ['name' => 'save', 'line' => 15, 'api' => 'shouldReceive', 'returns' => [], 'called_with' => [['api' => 'with', 'line' => 15, 'count' => 1, 'spread' => false]]];
        $walker = DriftFixtures::phpType('class', 'App\Walker', [], 'src/Walker.php');
        $each = DriftFixtures::phpMethod($walker, 'each');
        $provider = DriftFixtures::phpMethod(DriftFixtures::phpType('class', 'Tests\WalkerTest', [], 'tests/WalkerTest.php'), 'rows');
        $reach = static fn(string $target, bool $callable = true, ?NodeFact $from = null): EdgeFact => new EdgeFact('calls', ($from ?? $each)->localId, $target, Origin::Ast, Confidence::Probable, ($from ?? $each)->evidence, $callable ? ['speculative' => true, 'callable' => true] : []);
        $parent = DriftFixtures::phpType('interface', 'App\Saves', ['member_names' => []], 'src/Saves.php');
        $lineage = [DriftFixtures::edge('implements', DriftFixtures::phpType('class', 'App\UserService'), $parent->localId)];
        $graph = static fn(array $member, array $edges, array $extra = [], array $double = []): TestFidelityGraph => DriftFixtures::phpGraph([['save', $save]], [$member], $double, extra: [$walker, $each, $provider, ...$extra], extraEdges: $edges);

        assertSame([], self::findings($graph($tooMany, [$reach('php:method:App\UserService::save')])));
        assertSame([], self::findings($graph($tooMany, [$reach('php:method:\app\userservice::SAVE')])), 'PHP names match in any case.');
        assertSame([], self::findings($graph($replacement, [$reach('php:method:App\UserService::save')])));
        assertSame([], self::findings($graph($tooMany, [$reach('php:method:App\Saves::save'), ...$lineage], [$parent])), 'Reached through an interface the target implements.');
        assertSame(['definite save needs 2 argument(s); the matcher expects 1'], self::messages(self::findings($graph($tooFew, [$reach('php:method:App\UserService::save')], [], ['framework' => 'mockery']))));
        assertSame(['definite save takes 2 argument(s); the matcher expects 3'], self::messages(self::findings($graph($tooMany, []))), 'Called only directly, it takes no extra.');
        assertCount(1, self::findings($graph($tooMany, [$reach('php:method:App\UserService::save', false)])));
        assertCount(1, self::findings($graph($tooMany, [$reach('php:method:App\UserService::find')])));
        assertCount(1, self::findings($graph($tooMany, [$reach('php:method:App\Other::save')])));
        assertCount(1, self::findings($graph($tooMany, [$reach('php:method_of_property:App\UserService::save')])));
        assertCount(1, self::findings($graph($replacement, [])));
        assertCount(1, self::findings($graph($tooMany, [$reach('php:method:App\UserService::save', true, $provider)])), 'A test that lists the callable, a data provider, hands nothing to production.');
        assertCount(1, self::findings($graph($tooMany, [new EdgeFact('calls', 'php:method:App\Gone::each', 'php:method:App\UserService::save', Origin::Ast, Confidence::Probable, $walker->evidence, ['callable' => true])])), 'A source the scan does not hold is no production.');
        assertCount(1, self::findings($graph($tooMany, [new EdgeFact('references', $each->localId, 'php:method:App\UserService::save', Origin::Ast, Confidence::Probable, $each->evidence, ['callable' => true])])), 'Only a calls edge reaches a method.');
    }

    /** @return list<\Knossos\TestFidelity\TestFinding> */
    private static function findings(TestFidelityGraph $graph): array
    {
        return [...(new ArityMismatchRule())->findings($graph)];
    }
}
