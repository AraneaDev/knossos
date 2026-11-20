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
 * above what the member takes is wrong there.
 */
final class CallArity
{
    private function __construct() {}

    /**
     * What is wrong with the first matcher whose count the member cannot meet, or null.
     *
     * @param list<CallMatcher> $matchers
     * @return array{matcher: CallMatcher, message: string, expected: int}|null
     */
    public static function violation(array $matchers, SignatureShape $declared, string $member, string $framework): ?array
    {
        if ($declared->isVariadic()) {
            return null;
        }
        foreach ($matchers as $matcher) {
            if ($matcher->spread) {
                continue;
            }
            if ($matcher->count > count($declared->params)) {
                return ['matcher' => $matcher, 'expected' => count($declared->params), 'message' => sprintf('%s takes %d argument(s); the matcher expects %d', $member, count($declared->params), $matcher->count)];
            }
            if (self::isExact($matcher, $framework) && $matcher->count < $declared->requiredCount()) {
                return ['matcher' => $matcher, 'expected' => $declared->requiredCount(), 'message' => sprintf('%s needs %d argument(s); the matcher expects %d', $member, $declared->requiredCount(), $matcher->count)];
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
