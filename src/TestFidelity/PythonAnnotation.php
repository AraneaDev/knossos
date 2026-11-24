<?php

declare(strict_types=1);

namespace Knossos\TestFidelity;

/**
 * A Python annotation's text read into a tree, for `PythonTypeFit`: a name
 * and what it is subscripted with (`dict[str, list[int]]`), a union of `|`
 * members, a quoted annotation (`"Repo"`, read again by whoever asks), or any
 * other well-formed atom (a number, an ellipsis, a bracketed list).
 *
 * Nothing is evaluated or resolved: the names are the text as written.
 */
final readonly class PythonAnnotation
{
    /** A name, with or without arguments. */
    public const NAME = 'name';

    /** `a | b | …`. */
    public const UNION = 'union';

    /** A quoted annotation, its text unquoted. */
    public const QUOTED = 'quoted';

    /** A number, an ellipsis or a bracketed list: well-formed, but no type. */
    public const OTHER = 'other';

    /** One token: a dotted name, a bracket, a comma, a bar, a quoted string, an ellipsis or a number. */
    private const TOKEN = '/\G\s*(?:([A-Za-z_][A-Za-z0-9_.]*)|([\[\],|])|("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\')|(\.\.\.|-?[0-9][A-Za-z0-9_.]*))/';

    /**
     * @param string $form one of the form constants
     * @param string $text the name, or the quoted text; empty for the other forms
     * @param list<self>|null $arguments what a name is subscripted with, null when it is not
     * @param list<self> $members a union's members
     */
    private function __construct(public string $form, public string $text = '', public ?array $arguments = null, public array $members = []) {}

    /** A name, as a reader may build one: `None` for `Optional`'s missing half. */
    public static function name(string $text): self
    {
        return new self(self::NAME, $text);
    }

    /**
     * A union of the given members.
     *
     * @param list<self> $members
     */
    public static function union(array $members): self
    {
        return new self(self::UNION, members: $members);
    }

    /** The annotation the text spells; null when it does not parse. */
    public static function parse(string $text): ?self
    {
        $tokens = self::tokens($text);
        if ($tokens === null) {
            return null;
        }
        $position = 0;
        $annotation = self::members($tokens, $position);

        return $annotation !== null && $position === count($tokens) ? $annotation : null;
    }

    /**
     * The text's tokens, each `[kind, text]`; null when some text is no token.
     *
     * @return list<array{string, string}>|null
     */
    private static function tokens(string $text): ?array
    {
        $tokens = [];
        $offset = 0;
        $text = rtrim($text);
        while ($offset < strlen($text)) {
            if (preg_match(self::TOKEN, $text, $match, 0, $offset) !== 1) {
                return null;
            }
            $offset += strlen($match[0]);
            [$name, $punctuation, $quoted] = [$match[1], $match[2] ?? '', $match[3] ?? ''];
            $tokens[] = match (true) {
                $name !== '' => [self::NAME, $name],
                $punctuation !== '' => [$punctuation, $punctuation],
                $quoted !== '' => [self::QUOTED, stripcslashes(substr($quoted, 1, -1))],
                default => [self::OTHER, ''],
            };
        }

        return $tokens;
    }

    /**
     * `a | b | …`, or the one term when there is no bar.
     *
     * @param list<array{string, string}> $tokens
     */
    private static function members(array $tokens, int &$position): ?self
    {
        $members = [];
        while (true) {
            $term = self::term($tokens, $position);
            if ($term === null) {
                return null;
            }
            $members[] = $term;
            if (($tokens[$position][0] ?? null) !== '|') {
                break;
            }
            ++$position;
        }

        return count($members) === 1 ? $members[0] : self::union($members);
    }

    /**
     * One name with what it is subscripted with, a quoted annotation, or another atom.
     *
     * @param list<array{string, string}> $tokens
     */
    private static function term(array $tokens, int &$position): ?self
    {
        [$kind, $text] = $tokens[$position] ?? ['', ''];
        ++$position;
        if ($kind === '[') {
            return self::arguments($tokens, $position) === null ? null : new self(self::OTHER);
        }
        if ($kind === self::QUOTED || $kind === self::OTHER) {
            return new self($kind, $text);
        }
        if ($kind !== self::NAME) {
            return null;
        }
        if (($tokens[$position][0] ?? null) !== '[') {
            return self::name($text);
        }
        ++$position;
        $arguments = self::arguments($tokens, $position);

        return $arguments === null ? null : new self(self::NAME, $text, $arguments);
    }

    /**
     * The comma-separated annotations up to the closing bracket, which is consumed.
     *
     * @param list<array{string, string}> $tokens
     * @return list<self>|null
     */
    private static function arguments(array $tokens, int &$position): ?array
    {
        $arguments = [];
        while (($tokens[$position][0] ?? null) !== ']') {
            $argument = self::members($tokens, $position);
            if ($argument === null) {
                return null;
            }
            $arguments[] = $argument;
            $next = $tokens[$position][0] ?? null;
            if ($next === ',') {
                ++$position;
            } elseif ($next !== ']') {
                return null;
            }
        }
        ++$position;

        return $arguments;
    }
}
