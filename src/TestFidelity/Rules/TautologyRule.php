<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\Rules;

use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\TestFidelity\Facts\{Assertion, Operand, TestCaseFact, TestDouble};
use Knossos\TestFidelity\{FindingCategory, FindingConfidence, TestFidelityGraph, TestFidelityRule, TestFinding};

/**
 * An assertion that cannot fail, or a stub nothing can run: Momus's TAUT-001
 * to TAUT-006.
 *
 * One finding at most per assertion. Where several cases match, the first of
 * this order wins, the definite ones before the heuristics and the narrower
 * before the broader: `self_comparison`, `mock_echo`, `constant`,
 * `unconfigured_spy`, `mock_only`. `zero_reach` is about a double, not an
 * assertion, so it stands apart.
 *
 * Where the facts run out it is silent: an operand with no text cannot be
 * shown equal to anything, a negated matcher is not a self-comparison, and a
 * test that runs production may reach a double through it, so neither
 * `unconfigured_spy` nor `zero_reach` speaks there.
 */
final readonly class TautologyRule implements TestFidelityRule
{
    public const ID = 'test.fidelity.tautology.v1';

    /** Matchers that state a property of one value, so two constants under them are not a tautology. */
    private const CONSTANT_APIS = ['toBeNull', 'toBeUndefined', 'toBeTruthy', 'toBeFalsy', 'toBeInstanceOf', 'toHaveLength'];

    /** Where an operand's value comes from a double. */
    private const MOCK_SOURCES = ['mock-config', 'mock-call'];

    /** The id every finding of this rule carries. */
    public function id(): string
    {
        return self::ID;
    }

    /**
     * Every assertion's verdict in the graph's test order, then every double's.
     *
     * @return iterable<TestFinding>
     */
    public function findings(TestFidelityGraph $graph): iterable
    {
        $doubles = $asserted = [];
        foreach ($graph->doubles() as $double) {
            $doubles[$double->canonicalName] = $double;
        }
        foreach ($graph->testCases() as $case) {
            $node = $graph->nodeOf($case);
            foreach ($case->assertions as $assertion) {
                foreach ($assertion->operands as $operand) {
                    if ($operand->double !== null) {
                        $asserted[$operand->double] = true;
                    }
                }
                $finding = self::judge($assertion, $case, $node, $doubles);
                if ($finding !== null) {
                    yield $finding;
                }
            }
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
    private static function judge(Assertion $assertion, TestCaseFact $case, NodeFact $node, array $doubles): ?TestFinding
    {
        [$left, $right] = $assertion->operands + [null, null];
        $verdict = self::compared($assertion, $left, $right)
            ?? self::unconfiguredSpy($assertion, $case, $doubles)
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
    private static function compared(Assertion $assertion, ?Operand $left, ?Operand $right): ?array
    {
        if ($left === null || $right === null) {
            return null;
        }
        if (!$assertion->negated && $left->text !== null && $left->text === $right->text) {
            return ['self_comparison', FindingConfidence::Definite, DriftText::clean($left->text) . ' is compared with itself; the assertion cannot fail', []];
        }
        $echoed = $assertion->negated ? null : (self::echo($left, $right) ?? self::echo($right, $left));
        if ($echoed !== null) {
            return ['mock_echo', FindingConfidence::Definite, 'the assertion compares the stubbed value ' . DriftText::clean($echoed) . ' with itself; it cannot fail', []];
        }
        if ($left->source === 'literal' && $right->source === 'literal' && !in_array($assertion->api, self::CONSTANT_APIS, true)) {
            return ['constant', FindingConfidence::Definite, sprintf('%s is compared with %s, both constants; the assertion cannot fail', DriftText::clean($left->text ?? 'a literal'), DriftText::clean($right->text ?? 'a literal')), []];
        }

        return null;
    }

    /** The value a stub was pinned to, when the other operand is that same literal. */
    private static function echo(Operand $stubbed, Operand $literal): ?string
    {
        return $stubbed->source === 'mock-config' && $stubbed->value !== null && $literal->source === 'literal' && $literal->text === $stubbed->value ? $stubbed->value : null;
    }

    /**
     * TAUT-006: a call matcher on a spy that nothing stubs, nothing in the
     * file calls, and that no production code the test runs could reach.
     *
     * @param array<string, TestDouble> $doubles
     * @return array{string, FindingConfidence, string, array<string, string>}|null
     */
    private static function unconfiguredSpy(Assertion $assertion, TestCaseFact $case, array $doubles): ?array
    {
        $operand = $assertion->operands[0] ?? null;
        if ($operand === null || !$operand->unconfiguredSpy || $operand->double === null) {
            return null;
        }
        $spy = $doubles[$operand->double] ?? null;
        if ($spy === null || $spy->invocations !== 0 || $case->productionCalls !== 0 || !str_starts_with($assertion->api, 'toHaveBeenCalled')) {
            return null;
        }

        return ['unconfigured_spy', FindingConfidence::Warning, 'the spy is not stubbed and the test runs no production code, so nothing could have called it', ['double' => $spy->canonicalName]];
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
     * to every test of its file, and to none when the file has none.
     */
    private static function isZeroReach(TestDouble $double, TestFidelityGraph $graph): bool
    {
        if ($double->invocations !== 0 || !self::isPinned($double)) {
            return false;
        }
        $tests = DoubleTests::of($double, $graph);
        foreach ($tests as $case) {
            if ($case->productionCalls !== 0 || $case->shouldPanic === true) {
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
