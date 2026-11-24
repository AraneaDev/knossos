<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\Rules;

use Knossos\TestFidelity\Facts\CallMatcher;

/**
 * Whether a Python call matcher's arguments bind to the member it is about, as
 * Python binds a call's arguments to a signature.
 *
 * A `Mock` assertion such as `assert_called_with(1, user=u)` matches only a
 * call made with exactly those arguments, so one that no real call of the
 * member could be made with passes only while production makes a call the
 * member rejects. The matcher's positional arguments bind to the parameters a
 * position may pass, in order; its keywords bind by name to the parameters a
 * keyword may pass. `*args` takes any further positional argument and
 * `**kwargs` any further keyword, each opening only its own half.
 *
 * The receiver is not in the member's signature: a matcher on a form that
 * records it (a class-level `autospec=True`, `mocker.spy` on a class) comes
 * spread from the worker and is never judged here. A keyword list longer than
 * the count, or naming a keyword twice, is malformed and gives no verdict.
 */
final class PythonCallBinding
{
    /** The `kind` of a parameter only a position can pass. */
    private const POSITIONAL_ONLY = 'positional_only';

    /** The `kind` of a parameter only a keyword can pass. */
    private const KEYWORD_ONLY = 'keyword_only';

    private function __construct() {}

    /**
     * What is wrong with the matcher, checked in order: too many positional
     * arguments, a keyword the member does not take by name, a required
     * parameter left unbound; null when it binds or cannot be judged.
     *
     * @return array{message: string, expected: int}|null
     */
    public static function violation(CallMatcher $matcher, SignatureShape $declared, string $member): ?array
    {
        $keywords = $matcher->keywords;
        $positional = $matcher->count - count($keywords);
        if ($positional < 0 || count(array_unique($keywords)) !== count($keywords)) {
            return null;
        }
        $byPosition = $byName = [];
        $restPositional = $restKeywords = false;
        foreach ($declared->params as $param) {
            if ($param['variadic']) {
                str_starts_with($param['name'], '**') ? $restKeywords = true : $restPositional = true;
                continue;
            }
            if ($param['kind'] !== self::KEYWORD_ONLY) {
                $byPosition[] = $param;
            }
            if ($param['kind'] !== self::POSITIONAL_ONLY) {
                $byName[$param['name']] = $param;
            }
        }
        if (!$restPositional && $positional > count($byPosition)) {
            return ['expected' => count($byPosition), 'message' => sprintf('%s takes %d argument(s); the matcher expects %d', $member, count($byPosition), $positional)];
        }
        $bound = array_fill_keys(array_map(static fn(array $param): string => $param['name'], array_slice($byPosition, 0, $positional)), true);
        foreach ($keywords as $keyword) {
            if (!$restKeywords && !isset($byName[$keyword])) {
                return ['expected' => count($byName), 'message' => sprintf('%s has no parameter %s; the matcher passes it by name', $member, DriftText::clean($keyword))];
            }
            // A keyword `**kwargs` takes binds no parameter, a positional-only one of that name included.
            if (isset($byName[$keyword])) {
                $bound[$keyword] = true;
            }
        }
        foreach ($declared->params as $param) {
            if (!$param['variadic'] && !$param['optional'] && !isset($bound[$param['name']])) {
                return ['expected' => $declared->requiredCount(), 'message' => sprintf('%s needs %d argument(s); the matcher expects %d', $member, $declared->requiredCount(), $matcher->count)];
            }
        }

        return null;
    }
}
