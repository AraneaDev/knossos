<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\Rules;

use Knossos\TestFidelity\Facts\CallMatcher;
use Knossos\TestFidelity\Facts\EnumCases;
use Knossos\TestFidelity\FindingCategory;
use Knossos\TestFidelity\FindingConfidence;
use Knossos\TestFidelity\TestFidelityGraph;
use Knossos\TestFidelity\TestFinding;

/**
 * A call matcher lists a literal for a parameter typed by a PHP enum, and
 * the literal is none of the enum's cases (G3-19): `with('rail')` for
 * `reroute(Leg $to)` once `Leg` lost its `rail` case. The arity rule reports
 * it as `ARITY_MISMATCH/enum_case`, one of the ways a matcher's arguments
 * cannot meet the member, as Nemesis does.
 *
 * Only what the evidence closes is judged. The PHP worker is the one that
 * sends a matcher's `literals` (a string or an integer the syntax states)
 * and an enum's `backing`, so another language is never judged; nor is a
 * spread or negated matcher, an argument that is no literal, a parameter
 * whose type is anything but the enum alone (`?Leg`, `Leg|string`, a class,
 * a rest parameter), or an enum whose case list is absent, truncated,
 * empty, or whose backing the worker does not state. A backed enum with a
 * computed case sends no values: there is nothing to hold a literal against.
 *
 * The confidence follows the evidence. Definite when the literal's type is
 * the enum's backing type and its value is no case's value, or a string
 * naming no case of a pure enum: the list is closed, so the value is surely
 * gone. A literal of another type (an integer for a string-backed or pure
 * enum, a string for an int-backed one) is a warning, as it is no case of
 * any list; silent where its text is a case's value. A pin on the return
 * side is RETURN_DRIFT's: no literal is an enum instance, so `return_type`
 * already refuses it.
 */
final class EnumCaseCheck
{
    /** The sub-rule its findings carry. */
    public const SUB_RULE = 'enum_case';

    private function __construct() {}

    /** The first matcher argument that is none of its enum parameter's cases, or null. */
    public static function finding(DriftPair $pair, SignatureShape $declared, TestFidelityGraph $graph, string $ruleId): ?TestFinding
    {
        if (!TestFidelityGraph::isPhp($pair->target)) {
            return null;
        }
        foreach ($pair->stub->calledWith as $matcher) {
            if ($matcher->spread || $matcher->negated) {
                continue;
            }
            foreach ($matcher->literals as $index => $literal) {
                $param = $declared->params[$index] ?? null;
                if ($literal === null || $param === null || $param['variadic'] || $param['type'] === null) {
                    continue;
                }
                $enum = self::enum($param['type'], $graph);
                $confidence = $enum === null ? null : self::confidence($literal, $enum[1]);
                if ($enum !== null && $confidence !== null) {
                    return self::report($pair, $matcher, $index, $literal, $enum[0], $confidence, $ruleId);
                }
            }
        }

        return null;
    }

    /**
     * The enum a parameter type names alone, as its short name and its
     * closed, non-empty case list with a stated backing; null for any other
     * type, a nullable, union or intersection type naming no node at all.
     *
     * @return array{string, EnumCases}|null
     */
    private static function enum(string $type, TestFidelityGraph $graph): ?array
    {
        $node = $graph->phpType($type);
        $cases = $node === null || $node->kind !== 'enum' ? null : EnumCases::ofNode($node);
        if ($node === null || $cases === null || $cases->cases() === [] || $cases->backing() === null) {
            return null;
        }

        return [$node->displayName, $cases];
    }

    /**
     * How sure it is that a literal is none of the cases; null when it is one.
     *
     * @param array{type: string, value: string} $literal
     */
    private static function confidence(array $literal, EnumCases $enum): ?FindingConfidence
    {
        $backing = $enum->backing();
        if ($backing === 'none') {
            if ($literal['type'] !== 'string') {
                return FindingConfidence::Warning;
            }

            return array_key_exists($literal['value'], $enum->cases()) ? null : FindingConfidence::Definite;
        }
        if (!$enum->backed()) {
            return null;
        }
        if (in_array($literal['value'], $enum->cases(), true)) {
            return null;
        }

        return $literal['type'] === $backing ? FindingConfidence::Definite : FindingConfidence::Warning;
    }

    /** @param array{type: string, value: string} $literal */
    private static function report(DriftPair $pair, CallMatcher $matcher, int $index, array $literal, string $enum, FindingConfidence $confidence, string $ruleId): ?TestFinding
    {
        $shown = $literal['type'] === 'string' ? "'" . $literal['value'] . "'" : $literal['value'];

        return $pair->finding(
            FindingCategory::ArityMismatch,
            $confidence,
            $ruleId,
            self::SUB_RULE,
            sprintf('%s takes a %s as argument %d, and %s is none of its cases (%s)', DriftText::clean($pair->stub->name), DriftText::clean($enum), $index + 1, DriftText::clean($shown), DriftText::clean($matcher->api)),
            ['enum' => DriftText::clean($enum), 'argument' => $index + 1, 'value' => DriftText::clean($literal['value']), 'matcher' => $matcher->api],
            $matcher->line,
        );
    }
}
