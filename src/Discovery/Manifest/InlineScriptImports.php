<?php

declare(strict_types=1);

namespace Knossos\Discovery\Manifest;

/**
 * The module specifiers a piece of JavaScript loads, read without a parser.
 *
 * An inline `<script>` in an HTML page is the only thing that reaches the
 * module it imports, and no scanner reads that text. Four forms load a
 * module: `import "x"`, `import … from "x"`, `export … from "x"` and a
 * dynamic `import()` of a string or template literal. Recognising them
 * needs only to know where code is: comments, strings, template text and
 * regular expression literals are skipped, so an `import(` written inside
 * any of them is never read as one.
 *
 * Lenient by design. Unterminated text ends the read, and anything the
 * reader cannot follow is passed over, because a page that loads nothing
 * this reader understands simply contributes no entry point.
 */
final class InlineScriptImports
{
    /**
     * Every specifier, in source order, with the byte offset of its opening
     * quote (or backtick) within `$script`.
     *
     * A template literal counts by its static prefix, the text before its
     * first `${`, and only when that prefix is not empty:
     * `` `/static/app.mjs${location.search}` `` loads `/static/app.mjs`, and
     * `` `${base}/x.js` `` names nothing a page can be read for. The
     * TypeScript worker follows the same rule for a module's own dynamic
     * imports (`templateImportHead` in
     * workers/typescript/src/dynamic-import-collector.js).
     *
     * @return list<array{specifier: string, offset: int}>
     */
    public static function specifiers(string $script): array
    {
        $found = [];
        self::code($script, 0, false, $found);

        return $found;
    }

    /**
     * Walk code from `$at`, recording specifiers, to the end of the script
     * or, inside a template's `${…}`, to the brace that closes it.
     *
     * @param list<array{specifier: string, offset: int}> $found
     * @return int the offset just past where the code ended
     */
    private static function code(string $script, int $at, bool $inSubstitution, array &$found): int
    {
        $length = strlen($script);
        $depth = 0;
        // Whether a `/` here would start a regular expression rather than divide.
        $expectsOperand = true;
        while ($at < $length) {
            $char = $script[$at];
            $next = $script[$at + 1] ?? '';
            if ($char === '/' && $next === '/') {
                $end = strpos($script, "\n", $at);
                $at = $end === false ? $length : $end;
            } elseif ($char === '/' && $next === '*') {
                $end = strpos($script, '*/', $at + 2);
                $at = $end === false ? $length : $end + 2;
            } elseif ($char === '"' || $char === "'") {
                $at = self::stringEnd($script, $at);
                $expectsOperand = false;
            } elseif ($char === '`') {
                $at = self::templateEnd($script, $at, $found);
                $expectsOperand = false;
            } elseif ($char === '/' && $expectsOperand) {
                $at = self::regexEnd($script, $at);
                $expectsOperand = false;
            } elseif (self::isIdentifierStart($char)) {
                $start = $at;
                while ($at < $length && self::isIdentifierPart($script[$at])) {
                    ++$at;
                }
                $word = substr($script, $start, $at - $start);
                $previous = self::previousSignificant($script, $start);
                if (($word === 'import' || $word === 'export') && $previous !== '.') {
                    self::keyword($script, $at, $word, $found);
                }
                // After a keyword such as `return` an operand follows; after a name, an operator.
                $expectsOperand = in_array($word, ['return', 'typeof', 'instanceof', 'in', 'of', 'new', 'delete', 'void', 'throw', 'case', 'do', 'else', 'yield', 'await'], true);
            } elseif ($char === '{') {
                ++$depth;
                ++$at;
                $expectsOperand = true;
            } elseif ($char === '}') {
                ++$at;
                if ($inSubstitution && $depth === 0) {
                    return $at;
                }
                --$depth;
                $expectsOperand = true;
            } else {
                ++$at;
                if (!ctype_space($char)) {
                    $expectsOperand = !in_array($char, [')', ']'], true) && !ctype_digit($char);
                }
            }
        }

        return $length;
    }

    /**
     * Record the specifier an `import` or `export` keyword ending at `$at`
     * loads, if it loads one. The caller resumes scanning just after the
     * keyword either way, so a clause this misreads cannot swallow code.
     *
     * @param list<array{specifier: string, offset: int}> $found
     */
    private static function keyword(string $script, int $at, string $word, array &$found): void
    {
        $at = self::skipTrivia($script, $at);
        $char = $script[$at] ?? '';
        if ($word === 'import' && $char === '(') {
            self::literal($script, self::skipTrivia($script, $at + 1), $found);

            return;
        }
        if ($word === 'import' && ($char === '"' || $char === "'")) {
            self::literal($script, $at, $found);

            return;
        }
        // `export const x`, `export default` and `import.meta` load nothing.
        if ($word === 'export' ? $char !== '{' && $char !== '*' : $char === '' || $char === '.' || $char === '=') {
            return;
        }
        $length = strlen($script);
        while ($at < $length) {
            $at = self::skipTrivia($script, $at);
            $char = $script[$at] ?? '';
            if ($char === '{') {
                $at = self::braceEnd($script, $at);
            } elseif ($char === '*' || $char === ',') {
                ++$at;
            } elseif (self::isIdentifierStart($char)) {
                $start = $at;
                while ($at < $length && self::isIdentifierPart($script[$at])) {
                    ++$at;
                }
                if (substr($script, $start, $at - $start) === 'from') {
                    $at = self::skipTrivia($script, $at);
                    if (($script[$at] ?? '') === '"' || ($script[$at] ?? '') === "'") {
                        self::literal($script, $at, $found);
                    }

                    return;
                }
            } else {
                return;
            }
        }
    }

    /**
     * Record the string or template literal starting at `$at`, when it names something.
     *
     * @param list<array{specifier: string, offset: int}> $found
     */
    private static function literal(string $script, int $at, array &$found): void
    {
        $quote = $script[$at] ?? '';
        if ($quote !== '"' && $quote !== "'" && $quote !== '`') {
            return;
        }
        $length = strlen($script);
        $end = $at + 1;
        while ($end < $length && $script[$end] !== $quote && $script[$end] !== "\n") {
            if ($quote === '`' && $script[$end] === '$' && ($script[$end + 1] ?? '') === '{') {
                break;
            }
            $end += $script[$end] === '\\' ? 2 : 1;
        }
        // A string cut off by the end of the script or a line names nothing.
        if ($end >= $length || ($quote !== '`' && $script[$end] !== $quote)) {
            return;
        }
        $specifier = substr($script, $at + 1, $end - $at - 1);
        if (trim($specifier) !== '') {
            $found[] = ['specifier' => $specifier, 'offset' => $at];
        }
    }

    /** The offset just past the string literal opening at `$at`. */
    private static function stringEnd(string $script, int $at): int
    {
        $quote = $script[$at];
        $length = strlen($script);
        for (++$at; $at < $length; ++$at) {
            if ($script[$at] === '\\') {
                ++$at;
            } elseif ($script[$at] === $quote || $script[$at] === "\n") {
                return $at + 1;
            }
        }

        return $length;
    }

    /**
     * The offset just past the template literal opening at `$at`. Its
     * `${…}` substitutions are code and are scanned as such.
     *
     * @param list<array{specifier: string, offset: int}> $found
     */
    private static function templateEnd(string $script, int $at, array &$found): int
    {
        $length = strlen($script);
        for (++$at; $at < $length; ++$at) {
            if ($script[$at] === '\\') {
                ++$at;
            } elseif ($script[$at] === '`') {
                return $at + 1;
            } elseif ($script[$at] === '$' && ($script[$at + 1] ?? '') === '{') {
                $at = self::code($script, $at + 2, true, $found) - 1;
            }
        }

        return $length;
    }

    /** The offset just past the regular expression literal opening at `$at`, which ends at its line. */
    private static function regexEnd(string $script, int $at): int
    {
        $length = strlen($script);
        $inClass = false;
        for (++$at; $at < $length; ++$at) {
            $char = $script[$at];
            if ($char === '\\') {
                ++$at;
            } elseif ($char === "\n") {
                return $at;
            } elseif ($char === '[') {
                $inClass = true;
            } elseif ($char === ']') {
                $inClass = false;
            } elseif ($char === '/' && !$inClass) {
                return $at + 1;
            }
        }

        return $length;
    }

    /** The offset just past the brace that closes the one opening at `$at`, strings skipped. */
    private static function braceEnd(string $script, int $at): int
    {
        $length = strlen($script);
        for (++$at; $at < $length; ++$at) {
            if ($script[$at] === '"' || $script[$at] === "'") {
                $at = self::stringEnd($script, $at) - 1;
            } elseif ($script[$at] === '}') {
                return $at + 1;
            }
        }

        return $length;
    }

    /** The offset of the first character from `$at` that is neither whitespace nor a comment. */
    private static function skipTrivia(string $script, int $at): int
    {
        $length = strlen($script);
        while ($at < $length) {
            if (ctype_space($script[$at])) {
                ++$at;
            } elseif (substr($script, $at, 2) === '//') {
                $end = strpos($script, "\n", $at);
                $at = $end === false ? $length : $end;
            } elseif (substr($script, $at, 2) === '/*') {
                $end = strpos($script, '*/', $at + 2);
                $at = $end === false ? $length : $end + 2;
            } else {
                break;
            }
        }

        return $at;
    }

    /** The last character before `$at` that is not whitespace, or '' at the start. */
    private static function previousSignificant(string $script, int $at): string
    {
        while ($at > 0 && ctype_space($script[$at - 1])) {
            --$at;
        }

        return $at > 0 ? $script[$at - 1] : '';
    }

    /** Whether a byte can begin a name: a letter, `_`, `$`, or any byte of a multibyte character. */
    private static function isIdentifierStart(string $char): bool
    {
        return $char !== '' && (ctype_alpha($char) || $char === '_' || $char === '$' || ord($char) >= 0x80);
    }

    /** Whether a byte can continue a name, which digits also do. */
    private static function isIdentifierPart(string $char): bool
    {
        return self::isIdentifierStart($char) || ctype_digit($char);
    }
}
