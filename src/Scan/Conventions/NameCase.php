<?php

declare(strict_types=1);

namespace Knossos\Scan\Conventions;

/**
 * How the file names of a population are written, the one rule both a folder's own facts and a subtree's merge apply.
 *
 * A name is read up to its first dot. `kebab` is lower-case words joined by
 * `-`, `snake` the same with `_`, `pascal` starts upper-case, `camel` starts
 * lower-case and has an upper-case letter; neither of the last two has `-` or
 * `_`. A single lower-case word (`state`) is `word`: it has no style of its own
 * and fits kebab, snake and camel case alike.
 */
final class NameCase
{
    /** Fewest files strictly in one style before a population is said to be named in it. */
    public const MIN_FILES = 3;

    /** Each style, `word` last. */
    private const STYLES = [
        'kebab' => '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)+$/',
        'snake' => '/^[a-z][a-z0-9]*(?:_[a-z0-9]+)+$/',
        'pascal' => '/^[A-Z][A-Za-z0-9]*$/',
        'camel' => '/^[a-z][a-z0-9]*[A-Z][A-Za-z0-9]*$/',
        'word' => '/^[a-z][a-z0-9]*$/',
    ];

    private function __construct() {}

    /** The style of a file name up to its first dot, `word` for a single lower-case word, or null for a name in none. */
    public static function style(string $name): ?string
    {
        foreach (self::STYLES as $style => $pattern) {
            if (preg_match($pattern, $name) === 1) {
                return $style;
            }
        }

        return null;
    }

    /**
     * The style most files are in, the name breaking ties, or null below MIN_FILES files strictly in it.
     *
     * The single words count with the winner when it is kebab, snake or camel
     * case, never with PascalCase.
     *
     * @param array<string, int> $counts files per style, `word` included
     * @return array{value: string, files: int}|null
     */
    public static function of(array $counts): ?array
    {
        $words = $counts['word'] ?? 0;
        unset($counts['word']);
        $styles = array_map('strval', array_keys($counts));
        usort($styles, static fn(string $a, string $b): int => $counts[$b] <=> $counts[$a] ?: strcmp($a, $b));
        if ($styles === [] || $counts[$styles[0]] < self::MIN_FILES) {
            return null;
        }

        return ['value' => $styles[0], 'files' => $counts[$styles[0]] + ($styles[0] === 'pascal' ? 0 : $words)];
    }
}
