<?php

declare(strict_types=1);

namespace KnossosPhpScanner;

use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\TraitUseAdaptation\Alias;

/**
 * The method names a class-like declares itself, for the test-fidelity rules:
 * its own methods, the ones its docblock gives it with `@method`, trait
 * aliases and an enum's built-ins.
 *
 * Inherited names stay with the parent; the graph walks the inheritance edges
 * to find them. The list is closed only while it is provably complete: a class
 * answering `__call` or `__callStatic`, forwarding to a `@mixin`, or carrying a
 * `@method` line that cannot be read with confidence is open.
 */
final class MemberNames
{
    /**
     * The sorted names a class-like declares or documents, and the open flag
     * when it may answer other names.
     *
     * @return array{member_names: list<string>, member_names_open?: true}
     */
    public static function of(Stmt\ClassLike $node): array
    {
        $names = [];
        $open = false;
        foreach ($node->getMethods() as $method) {
            $name = $method->name->toString();
            $names[$name] = true;
            $open = $open || in_array(strtolower($name), ['__call', '__callstatic'], true);
        }
        foreach ($node->getTraitUses() as $use) {
            foreach ($use->adaptations as $adaptation) {
                if ($adaptation instanceof Alias && $adaptation->newName !== null) {
                    $names[$adaptation->newName->toString()] = true;
                }
            }
        }
        if ($node instanceof Stmt\Enum_) {
            foreach ($node->scalarType === null ? ['cases'] : ['cases', 'from', 'tryFrom'] as $builtIn) {
                $names[$builtIn] = true;
            }
        }
        $doc = $node->getDocComment()?->getText() ?? '';
        $open = $open || preg_match('/@mixin\b/', $doc) === 1;
        preg_match_all('/@method\b(.*)$/m', $doc, $lines);
        foreach ($lines[1] as $line) {
            $name = self::documentedName($line);
            if ($name === null) {
                $open = true;
            } else {
                $names[$name] = true;
            }
        }
        $sorted = array_map('strval', array_keys($names));
        sort($sorted, SORT_STRING);

        return ['member_names' => $sorted, ...($open ? ['member_names_open' => true] : [])];
    }

    /**
     * The method a `@method` line names: the identifier before the first
     * parameter list that is not a callable type's (`Closure(int): void`),
     * skipping generics and shapes. Null when the line cannot be read.
     */
    private static function documentedName(string $line): ?string
    {
        $length = strlen($line);
        $nesting = 0;
        for ($at = 0; $at < $length; $at++) {
            $char = $line[$at];
            if ($char === '<' || $char === '{') {
                $nesting++;
            } elseif (($char === '>' || $char === '}') && $nesting > 0) {
                $nesting--;
            } elseif ($char === '(' && $nesting === 0) {
                $close = self::closingParenthesis($line, $at);
                if ($close === null) {
                    return null;
                }
                if ((ltrim(substr($line, $close + 1))[0] ?? '') === ':') {
                    $at = $close;
                    continue;
                }

                return preg_match('/(?<![\w\\\\])([A-Za-z_]\w*)$/', substr($line, 0, $at), $found) === 1 ? $found[1] : null;
            }
        }

        return null;
    }

    /** The offset of the parenthesis closing the one at `$open`, or null when the line ends first. */
    private static function closingParenthesis(string $line, int $open): ?int
    {
        $depth = 0;
        for ($at = $open, $length = strlen($line); $at < $length; $at++) {
            $depth += match ($line[$at]) {
                '(' => 1,
                ')' => -1,
                default => 0,
            };
            if ($depth === 0) {
                return $at;
            }
        }

        return null;
    }
}
