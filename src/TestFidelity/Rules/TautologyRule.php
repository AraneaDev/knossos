<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\Rules;

use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\TestFidelity\Facts\{Assertion, Operand, TestCaseFact, TestDouble};
use Knossos\TestFidelity\{FindingCategory, FindingConfidence, PythonResiduals, TestFidelityGraph, TestFidelityRule, TestFinding};

/**
 * An assertion that cannot fail, or a stub nothing can run: Momus's TAUT-001
 * to TAUT-006.
 *
 * One finding at most per assertion. Where several cases match, the first of
 * this order wins, the definite ones before the heuristics and the narrower
 * before the broader: `vacuous_negation`, `self_comparison`, `mock_echo`,
 * `constant`, `unconfigured_spy`, `mock_only`. `zero_reach` is about a
 * double, not an assertion, so it stands apart.
 *
 * Two constants under `.not` with the same text (`expect(true).not.toBe(true)`)
 * cannot pass rather than cannot fail, and are said so, still as `constant`,
 * under a matcher that holds for equal values (`toBe`, `toEqual`, the
 * non-strict orderings); `expect(1).not.toBeGreaterThan(1)` cannot fail (G1-17). A call matcher under `.not` that lists fewer arguments than the
 * member requires (`expect(spy).not.toHaveBeenCalledWith()` for `rate(x)`)
 * holds that no call lists them, which none can: `vacuous_negation`, a warning,
 * and no arity drift (`CallArity::vacuous()`, G3-02). A test the runner
 * expects to fail (Vitest's `it.fails`, `fails`) passes only when an assertion
 * fails, so none of its assertions is judged, nor a vacuous negated matcher
 * within its lines, nor a double it answers for zero-reach, as for a Rust
 * `should_panic` test (G1-10).
 *
 * Where the facts run out it is silent: an operand with no text cannot be
 * shown equal to anything, a negated matcher is not a self-comparison, and a
 * test that runs production may reach a double through it, so neither
 * `unconfigured_spy` nor `zero_reach` speaks there, and neither does
 * `zero_reach` for a double of a package, which code outside the project may reach.
 *
 * In Python the call matchers are the `Mock` assertions (`assert_called_with`,
 * `assert_has_calls`, their `await` forms…), and `assertIsInstance` states a
 * property of one value. Under `==` and the orderings a value need not equal
 * itself (NaN, an `__eq__` under test), so a Python self-comparison is definite
 * there only when both operands are literals; `is` and `assertIs` compare by
 * identity, where a name read twice is the same value (Ruling P20).
 */
final readonly class TautologyRule implements TestFidelityRule
{
    public const ID = 'test.fidelity.tautology.v1';

    /** Matchers that state a property of one value, so two constants under them are not a tautology: Vitest's and Jest's, PHPUnit's and Pest's. */
    private const CONSTANT_APIS = ['toBeNull', 'toBeUndefined', 'toBeTruthy', 'toBeFalsy', 'toBeInstanceOf', 'toHaveLength', 'assertNull', 'assertInstanceOf', 'assertCount', 'toHaveCount'];

    /** Python's `unittest` assertions that state a property of one value. */
    private const PYTHON_CONSTANT_APIS = ['assertIsInstance', 'assertNotIsInstance', 'assertIsNone', 'assertIsNotNone', 'assertTrue', 'assertFalse', 'assert'];

    /** The `Mock` API's assertions on the calls a Python double received. */
    private const PYTHON_CALL_MATCHERS = [
        'assert_called', 'assert_called_once', 'assert_called_with', 'assert_called_once_with', 'assert_any_call', 'assert_has_calls', 'assert_not_called',
        'assert_awaited', 'assert_awaited_once', 'assert_awaited_with', 'assert_awaited_once_with', 'assert_any_await', 'assert_has_awaits', 'assert_not_awaited',
    ];

    /** The Python assertions that compare by identity. */
    private const PYTHON_IDENTITY_APIS = ['assert is', 'assertIs'];

    /** Mockery's spy assertions, which hold a call against a spy as `toHaveBeenCalled*` does. */
    private const SPY_ASSERTIONS = ['shouldHaveReceived', 'shouldNotHaveReceived'];

    /** The matchers that hold of a value compared with itself, so under `.not` two equal constants cannot pass (G1-17); a strict ordering does not hold, so under `.not` it cannot fail. */
    private const EQUAL_HOLDING_APIS = ['toBe', 'toEqual', 'toStrictEqual', 'toBeGreaterThanOrEqual', 'toBeLessThanOrEqual'];

    /** Where an operand's value comes from a double. */
    private const MOCK_SOURCES = ['mock-config', 'mock-call'];

    /** The id every finding of this rule carries. */
    public function id(): string
    {
        return self::ID;
    }

    /**
     * Every assertion's verdict in the graph's test order, then the vacuous
     * negated call matchers in the graph's double order, then every double's.
     *
     * @return iterable<TestFinding>
     */
    public function findings(TestFidelityGraph $graph): iterable
    {
        $doubles = $asserted = [];
        foreach ($graph->doubles() as $double) {
            $doubles[$double->canonicalName] = $double;
        }
        $vacuous = self::vacuousNegations($graph);
        foreach ($graph->testCases() as $case) {
            $node = $graph->nodeOf($case);
            foreach ($case->assertions as $assertion) {
                foreach ($assertion->operands as $operand) {
                    if ($operand->double !== null) {
                        $asserted[$operand->double] = true;
                    }
                }
                $finding = $case->fails || isset($vacuous[$node->evidence->relativePath . ':' . $assertion->line]) ? null : self::judge($assertion, $case, $node, $doubles, TestFidelityGraph::isPython($node), TestFidelityGraph::isRust($node));
                // U1, G4-08: production the test reaches that writes a caller's frame may change the constant a Python local holds.
                if ($finding?->subRule === 'constant' && TestFidelityGraph::isPython($node) && PythonResiduals::readsLocal($assertion) && $graph->reachesPythonFrameWriter($case)) {
                    $finding = PythonResiduals::downgraded($finding, PythonResiduals::FRAME_WRITE);
                }
                if ($finding !== null) {
                    yield $finding;
                }
            }
        }
        foreach ($vacuous as $finding) {
            yield $finding;
        }
        foreach ($graph->doubles() as $double) {
            if (!isset($asserted[$double->canonicalName]) && self::isZeroReach($double, $graph)) {
                $node = $graph->nodeOf($double);
                yield self::finding($node->localId, $graph->target($double)?->localId, 'zero_reach', FindingConfidence::Warning, DriftText::clean($node->displayName) . ' is configured but never invoked or asserted', $node->evidence, ['pattern' => DriftText::clean($double->pattern)]);
            }
        }
    }

    /**
     * The first case one assertion matches, or null.
     *
     * @param array<string, TestDouble> $doubles by canonical name
     */
    private static function judge(Assertion $assertion, TestCaseFact $case, NodeFact $node, array $doubles, bool $python, bool $rust): ?TestFinding
    {
        [$left, $right] = $assertion->operands + [null, null];
        $verdict = self::compared($assertion, $left, $right, $python, $rust)
            ?? self::unconfiguredSpy($assertion, $case, $doubles, $python)
            ?? self::mockOnly($assertion, $case);
        if ($verdict === null) {
            return null;
        }
        [$subRule, $confidence, $message, $attributes] = $verdict;
        $evidence = $assertion->line >= 1 ? new Evidence($node->evidence->relativePath, $assertion->line, $assertion->line) : $node->evidence;

        return self::finding($node->localId, null, $subRule, $confidence, $message, $evidence, ['api' => DriftText::clean($assertion->api), ...$attributes]);
    }

    /**
     * TAUT-001 to TAUT-003, over the first two operands: one compared with
     * itself, a stub's pinned value compared with that same literal, two constants.
     *
     * @return array{string, FindingConfidence, string, array<string, string>}|null
     */
    private static function compared(Assertion $assertion, ?Operand $left, ?Operand $right, bool $python, bool $rust): ?array
    {
        if ($left === null || $right === null) {
            return null;
        }
        if (!$assertion->negated && $left->text !== null && $left->text === $right->text && self::selfSameNeedsLiterals($assertion, $left, $right, $python, $rust)) {
            return ['self_comparison', FindingConfidence::Definite, DriftText::clean($left->text) . ' is compared with itself; the assertion cannot fail', []];
        }
        $echoed = $assertion->negated ? null : (self::echo($left, $right) ?? self::echo($right, $left));
        if ($echoed !== null) {
            return ['mock_echo', FindingConfidence::Definite, 'the assertion compares the stubbed value ' . DriftText::clean($echoed) . ' with itself; it cannot fail', []];
        }
        if ($left->source === 'literal' && $right->source === 'literal' && !in_array($assertion->api, $python ? self::PYTHON_CONSTANT_APIS : self::CONSTANT_APIS, true)) {
            // A negated comparison of one constant with itself always fails, under a matcher that holds for equal values (G1-17).
            $verdict = $assertion->negated && $left->text !== null && $left->text === $right->text && in_array($assertion->api, self::EQUAL_HOLDING_APIS, true) ? 'the assertion cannot pass' : 'the assertion cannot fail';

            return ['constant', FindingConfidence::Definite, sprintf('%s is compared with %s, both constants; %s', DriftText::clean($left->text ?? 'a literal'), DriftText::clean($right->text ?? 'a literal'), $verdict), []];
        }

        return null;
    }

    /**
     * Whether two operands of the same text cannot differ. Outside Python and
     * Rust the text alone decides. Python's `==` need not hold of a name
     * (NaN, an `__eq__` under test), so it takes identity or two literals
     * (Ruling P20). Rust's `PartialEq` need not be reflexive either, and it has
     * no identity matcher, so it takes two literals.
     */
    private static function selfSameNeedsLiterals(Assertion $assertion, Operand $left, Operand $right, bool $python, bool $rust): bool
    {
        $literals = $left->source === 'literal' && $right->source === 'literal';
        if ($rust) {
            return $literals;
        }

        return !$python || $literals || in_array($assertion->api, self::PYTHON_IDENTITY_APIS, true);
    }

    /** The value a stub was pinned to, when the other operand is that same literal, written or held by a local (its `value`). */
    private static function echo(Operand $stubbed, Operand $literal): ?string
    {
        return $stubbed->source === 'mock-config' && $stubbed->value !== null && $literal->source === 'literal' && ($literal->value ?? $literal->text) === $stubbed->value ? $stubbed->value : null;
    }

    /**
     * TAUT-006: a call matcher on a spy that nothing stubs, nothing in the
     * file calls, and that no production code the test runs could reach.
     *
     * @param array<string, TestDouble> $doubles
     * @return array{string, FindingConfidence, string, array<string, string>}|null
     */
    private static function unconfiguredSpy(Assertion $assertion, TestCaseFact $case, array $doubles, bool $python): ?array
    {
        $operand = $assertion->operands[0] ?? null;
        if ($operand === null || !$operand->unconfiguredSpy || $operand->double === null) {
            return null;
        }
        $spy = $doubles[$operand->double] ?? null;
        if ($spy === null || $spy->invocations !== 0 || $case->productionCalls !== 0 || !($python ? in_array($assertion->api, self::PYTHON_CALL_MATCHERS, true) : self::isCallMatcher($assertion->api))) {
            return null;
        }

        return ['unconfigured_spy', FindingConfidence::Warning, 'the spy is not stubbed and the test runs no production code, so nothing could have called it', ['double' => DriftText::clean($spy->canonicalName)]];
    }

    /**
     * G3-02: every call matcher under `.not` that lists fewer arguments than
     * the stubbed member requires, by where it stands (`path:line`), the first
     * on a line kept. Only a signature the arity rule would judge is read: a
     * cut-short, overloaded or rest-taking one says nothing.
     *
     * @return array<string, TestFinding>
     */
    private static function vacuousNegations(TestFidelityGraph $graph): array
    {
        $found = [];
        $failing = self::failingRanges($graph);
        foreach (DriftScope::pairs($graph) as $pair) {
            $finding = self::vacuousNegation($pair);
            if ($finding !== null && !self::within($failing[$finding->evidence->relativePath] ?? [], $finding->evidence->startLine)) {
                $found[$finding->evidence->relativePath . ':' . $finding->evidence->startLine] ??= $finding;
            }
        }

        return $found;
    }

    /**
     * The lines of every test the runner expects to fail (`it.fails`), by file.
     *
     * @return array<string, list<array{int, int}>>
     */
    private static function failingRanges(TestFidelityGraph $graph): array
    {
        $ranges = [];
        foreach ($graph->testCases() as $case) {
            if ($case->fails) {
                $evidence = $graph->nodeOf($case)->evidence;
                $ranges[$evidence->relativePath][] = [$evidence->startLine, $evidence->endLine];
            }
        }

        return $ranges;
    }

    /**
     * Whether a line falls within one of the ranges.
     *
     * @param list<array{int, int}> $ranges
     */
    private static function within(array $ranges, int $line): bool
    {
        foreach ($ranges as [$start, $end]) {
            if ($line >= $start && $line <= $end) {
                return true;
            }
        }

        return false;
    }

    /** The finding on one stubbed member's first vacuous negated call matcher, or null (only the TypeScript worker sends a negated one). */
    private static function vacuousNegation(DriftPair $pair): ?TestFinding
    {
        $declared = $pair->member === null ? null : SignatureShape::ofMember($pair->member->attributes);
        if ($declared === null) {
            return null;
        }
        $matcher = CallArity::vacuous($pair->stub->calledWith, $declared, $pair->double->framework);

        return $matcher === null ? null : $pair->finding(
            FindingCategory::Tautology,
            FindingConfidence::Warning,
            self::ID,
            'vacuous_negation',
            sprintf('%s needs %d argument(s); the negated %s expects %d, so it cannot fail', DriftText::clean($pair->stub->name), $declared->requiredCount(), DriftText::clean($matcher->api), $matcher->count),
            ['api' => DriftText::clean($matcher->api)],
            $matcher->line,
        );
    }

    /** Whether a matcher asserts a call on a spy: `toHaveBeenCalled*`, or Mockery's `shouldHaveReceived()`. */
    private static function isCallMatcher(string $api): bool
    {
        return str_starts_with($api, 'toHaveBeenCalled') || in_array($api, self::SPY_ASSERTIONS, true);
    }

    /**
     * TAUT-004: every operand comes from a double, in a test that runs no production.
     *
     * @return array{string, FindingConfidence, string, array<string, string>}|null
     */
    private static function mockOnly(Assertion $assertion, TestCaseFact $case): ?array
    {
        if ($case->productionCalls !== 0 || $assertion->operands === []) {
            return null;
        }
        foreach ($assertion->operands as $operand) {
            if (!in_array($operand->source, self::MOCK_SOURCES, true)) {
                return null;
            }
        }

        return ['mock_only', FindingConfidence::Warning, 'every operand comes from a double and the test runs no production code', []];
    }

    /**
     * TAUT-005, less what no assertion names: a double with a pinned value
     * that nothing in its file calls or hands on, whose tests run no
     * production that could reach it. A double made outside any test answers
     * to every test of its file, and to none when the file has none. A double
     * of something outside the project is never zero-reach: code outside the
     * project, which no production count sees, may call it.
     */
    private static function isZeroReach(TestDouble $double, TestFidelityGraph $graph): bool
    {
        if ($double->invocations !== 0 || !self::isPinned($double) || $graph->isExternal($double)) {
            return false;
        }
        $tests = DoubleTests::of($double, $graph);
        foreach ($tests as $case) {
            if ($case->productionCalls !== 0 || $case->shouldPanic === true || $case->fails) {
                return false;
            }
        }

        return $tests !== [];
    }

    /** Whether the test pins a value on any member of the double. */
    private static function isPinned(TestDouble $double): bool
    {
        foreach ($double->members as $member) {
            if ($member->returns !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * A finding of this rule about a test case or a double.
     *
     * @param array<string, string> $attributes
     */
    private static function finding(string $reference, ?string $target, string $subRule, FindingConfidence $confidence, string $message, Evidence $evidence, array $attributes): TestFinding
    {
        return new TestFinding($reference, $target, FindingCategory::Tautology, $confidence, self::ID, $subRule, $message, $evidence, $attributes);
    }
}
