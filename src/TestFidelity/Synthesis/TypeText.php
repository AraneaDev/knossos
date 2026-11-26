<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\Synthesis;

/**
 * A type as a worker writes it, taken apart at its top level: the members
 * of a union, the arguments of a generic, the elements of a tuple. Only the
 * brackets are read (`<>`, `()`, `[]`, `{}`) and the quotes; an arrow
 * (`=>`, `->`) closes nothing.
 */
final class TypeText
{
    private function __construct() {}

    /**
     * `$text` cut at each top-level `$separator`, each part trimmed; empty parts are dropped.
     *
     * @return list<string>
     */
    public static function split(string $text, string $separator): array
    {
        $parts = [];
        $depth = 0;
        $quote = null;
        $current = '';
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];
            if ($quote !== null) {
                $current .= $char;
                if ($char === '\\' && $i + 1 < $length) {
                    $current .= $text[++$i];
                } elseif ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '"' || $char === "'" || $char === '`') {
                // A Rust lifetime (`'a`) is no quote: it has no closing one before a non-word character.
                if ($char === "'" && preg_match('/\G\'[A-Za-z_]\w*+(?!\')/', $text, $match, 0, $i) === 1) {
                    $current .= $match[0];
                    $i += strlen($match[0]) - 1;
                    continue;
                }
                $quote = $char;
            } elseif (str_contains('<([{', $char)) {
                $depth++;
            } elseif (str_contains('>)]}', $char) && !($char === '>' && $i > 0 && ($text[$i - 1] === '=' || $text[$i - 1] === '-'))) {
                $depth = max(0, $depth - 1);
            } elseif ($depth === 0 && substr_compare($text, $separator, $i, strlen($separator)) === 0) {
                $parts[] = trim($current);
                $current = '';
                $i += strlen($separator) - 1;
                continue;
            }
            $current .= $char;
        }
        $parts[] = trim($current);

        return array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));
    }

    /**
     * A generic written `Name<A, B>` (`$open` `<`) or `Name[A, B]` (`[`):
     * its name and its top-level arguments; null for anything else.
     *
     * @return array{string, list<string>}|null
     */
    public static function generic(string $text, string $open = '<'): ?array
    {
        $close = $open === '<' ? '>' : ']';
        $text = trim($text);
        $at = strpos($text, $open);
        if ($at === false || $at === 0 || !str_ends_with($text, $close) || preg_match('/^[\w.:\\\\]+$/', substr($text, 0, $at)) !== 1) {
            return null;
        }
        $inner = substr($text, $at + 1, -1);

        return self::balanced($inner) ? [substr($text, 0, $at), self::split($inner, ',')] : null;
    }

    /** The text inside one pair of brackets that encloses all of `$text` (`(A, B)` gives `A, B`); null when they do not. */
    public static function enclosed(string $text, string $open, string $close): ?string
    {
        $text = trim($text);
        if (!str_starts_with($text, $open) || !str_ends_with($text, $close) || strlen($text) < 2) {
            return null;
        }
        $inner = substr($text, 1, -1);

        return self::balanced($inner) ? $inner : null;
    }

    /** Whether every bracket of `$text` closes within it, and none closes one it did not open. */
    private static function balanced(string $text): bool
    {
        $depth = 0;
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];
            if (str_contains('<([{', $char)) {
                $depth++;
            } elseif (str_contains('>)]}', $char) && !($char === '>' && $i > 0 && ($text[$i - 1] === '=' || $text[$i - 1] === '-'))) {
                if (--$depth < 0) {
                    return false;
                }
            }
        }

        return $depth === 0;
    }

    /**
     * `$text` with each whole-word `$names` replaced by `$with`.
     *
     * @param list<string> $names
     */
    public static function substitute(string $text, array $names, string $with): string
    {
        if ($names === []) {
            return $text;
        }

        return (string) preg_replace('/(?<![\w$.\'])(?:' . implode('|', array_map(static fn(string $name): string => preg_quote($name, '/'), $names)) . ')(?![\w$])/', $with, $text);
    }

    /**
     * Whether `$text` names any of `$names` as a whole word.
     *
     * @param list<string> $names
     */
    public static function mentions(string $text, array $names): bool
    {
        return $names !== [] && self::substitute($text, $names, "\0") !== $text;
    }

    /**
     * The name a type parameter declares, from its written text: `T` of
     * `T extends string = 'id'`, `T: Clone` or `const N: usize`, `'a` of `'a: 'b`.
     */
    public static function parameterName(string $written): string
    {
        $written = trim((string) preg_replace('/^(?:const|in|out)\s+/', '', trim($written)));

        return preg_match('/^\'?[\w$]+/', $written, $match) === 1 ? $match[0] : $written;
    }

    /** A type name in snake case, for a binding named after it: `HttpClient` gives `http_client`. */
    public static function snake(string $name): string
    {
        return strtolower((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', '_', $name));
    }
}
