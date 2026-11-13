<?php

declare(strict_types=1);

namespace Knossos\Scan\Conventions;

/**
 * Splits a name into its words, the one way both the folder facts and the brief do it.
 *
 * A word starts at a CamelCase boundary or after `_`, `-` or `.`, and a run of
 * capitals stays one word unless its last capital starts a lower-case word:
 * `FooHTTP` is `Foo`, `HTTP`; `HTTPClient` is `HTTP`, `Client`; `foo_service`
 * and `new-thing` split at the separator. Words compare without regard to case,
 * so `new-thing` and `NewThing` end in the same word.
 */
final class NameWords
{
    /** One word: a capital run not followed by a lower-case letter, or an optional capital and lower-case letters or digits. */
    private const WORD = '/[A-Z]+(?![a-z])|[A-Z]?[a-z0-9]+/';

    /**
     * The words of a name with the byte offset each starts at, in order.
     *
     * @return list<array{string, int}>
     */
    public static function split(string $name): array
    {
        preg_match_all(self::WORD, $name, $matches, PREG_OFFSET_CAPTURE);

        return array_map(static fn(array $match): array => [(string) $match[0], (int) $match[1]], $matches[0]);
    }

    /** The last word of a name in lower case, empty for a name without one. */
    public static function last(string $name): string
    {
        $words = self::split($name);

        return $words === [] ? '' : strtolower($words[count($words) - 1][0]);
    }
}
