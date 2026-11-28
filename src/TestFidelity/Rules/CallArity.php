<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\Rules;

use Knossos\TestFidelity\Facts\CallMatcher;

/**
 * Whether the argument count a call matcher lists fits the member it is about.
 *
 * Most matchers are exact: `toHaveBeenCalledWith(a, b)` fails for a call with a
 * third argument and for one with only `a`. A PHPUnit `with()` is not, because
 * PHPUnit leaves unconstrained the arguments it is not given, so only a count
 * above what the member takes is wrong there. A count above it is no wrong at
 * all for a PHP method production hands on as a callable (G3-31): what it is
 * handed to may pass more, and PHP drops the extra ones.
 *
 * A matcher read through `.not` that lists fewer arguments than the member
 * requires holds that no call lists them, which no call can: the assertion
 * cannot fail, so it is no drift of the signature but a vacuous assertion
 * (`vacuous()`, which `TautologyRule` reports; G3-02).
 *
 * A Python member's matchers are bound by name and kind (`PythonCallBinding`),
 * before the check that gives up on a rest parameter, since `*args` and
 * `**kwargs` each leave the other half of the call judgeable (Ruling P1).
 */
final class CallArity
{
    private function __construct() {}

    /**
     * What is wrong with the first matcher whose count the member cannot meet, or null.
     *
     * @param list<CallMatcher> $matchers
     * @param bool $takesMore whether the member may be called with more arguments than it takes
     * @return array{matcher: CallMatcher, message: string, expected: int}|null
     */
    public static function violation(array $matchers, SignatureShape $declared, string $member, string $framework, bool $python = false, bool $takesMore = false): ?array
    {
        if ($python) {
            return self::pythonViolation($matchers, $declared, $member);
        }
        if ($declared->isVariadic()) {
            return null;
        }
        foreach ($matchers as $matcher) {
            if ($matcher->spread) {
                continue;
            }
            if (!$takesMore && $matcher->count > count($declared->params)) {
                return ['matcher' => $matcher, 'expected' => count($declared->params), 'message' => sprintf('%s takes %d argument(s); the matcher expects %d', $member, count($declared->params), $matcher->count)];
            }
            if (!$matcher->negated && self::isExact($matcher, $framework) && $matcher->count < $declared->requiredCount()) {
                return ['matcher' => $matcher, 'expected' => $declared->requiredCount(), 'message' => sprintf('%s needs %d argument(s); the matcher expects %d', $member, $declared->requiredCount(), $matcher->count)];
            }
        }

        return null;
    }

    /**
     * The first matcher read through `.not` that lists fewer arguments than
     * the member requires, so no call can match it, or null. A spread
     * matcher, an inexact one and a member with a rest parameter are silent,
     * as for a violation.
     *
     * @param list<CallMatcher> $matchers
     */
    public static function vacuous(array $matchers, SignatureShape $declared, string $framework): ?CallMatcher
    {
        if ($declared->isVariadic()) {
            return null;
        }
        foreach ($matchers as $matcher) {
            if ($matcher->negated && !$matcher->spread && self::isExact($matcher, $framework) && $matcher->count < $declared->requiredCount()) {
                return $matcher;
            }
        }

        return null;
    }

    /**
     * What is wrong with the first Python matcher that does not bind, or null.
     *
     * @param list<CallMatcher> $matchers
     * @return array{matcher: CallMatcher, message: string, expected: int}|null
     */
    private static function pythonViolation(array $matchers, SignatureShape $declared, string $member): ?array
    {
        foreach ($matchers as $matcher) {
            $violation = $matcher->spread ? null : PythonCallBinding::violation($matcher, $declared, $member);
            if ($violation !== null) {
                return ['matcher' => $matcher, ...$violation];
            }
        }

        return null;
    }

    /** Whether the matcher fails for any argument list but the one it lists. */
    private static function isExact(CallMatcher $matcher, string $framework): bool
    {
        return !($matcher->api === 'with' && $framework === 'phpunit');
    }
}
