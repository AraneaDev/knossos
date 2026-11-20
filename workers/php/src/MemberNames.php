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
 * `@method` tag that is not in the simple form is open.
 */
final class MemberNames
{
    /** Words that read as a type, so a "name" spelled like one is the type of a form this reader does not take. */
    private const TYPE_WORDS = [
        'array', 'bool', 'boolean', 'callable', 'closure', 'false', 'float', 'int', 'integer', 'iterable', 'mixed',
        'never', 'null', 'object', 'parent', 'resource', 'self', 'static', 'string', 'this', 'true', 'void',
    ];

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
        [$documented, $documentedOpen] = self::fromDocblock($node->getDocComment()?->getText() ?? '');
        foreach ($documented as $name) {
            $names[$name] = true;
        }
        $sorted = array_map('strval', array_keys($names));
        sort($sorted, SORT_STRING);

        return ['member_names' => $sorted, ...($open || $documentedOpen ? ['member_names_open' => true] : [])];
    }

    /**
     * What a docblock's tags add: the names its `@method` tags give in the
     * simple form, and whether it leaves the list open (a `@mixin`, or a
     * `@method` tag in any other form). Tags are judged one by one, wherever
     * they sit on a line.
     *
     * @return array{list<string>, bool}
     */
    private static function fromDocblock(string $doc): array
    {
        $names = [];
        $open = false;
        preg_match_all('/(?<=[\s*])@([A-Za-z][\w-]*)(.*?)(?=(?<=[\s*])@[A-Za-z]|\z)/s', $doc, $tags, PREG_SET_ORDER);
        foreach ($tags as $tag) {
            if (in_array($tag[1], ['mixin', 'phpstan-mixin', 'psalm-mixin'], true)) {
                $open = true;
            } elseif (in_array($tag[1], ['method', 'phpstan-method', 'psalm-method'], true)) {
                $body = preg_replace('/\s*\n\s*\*?\s*/', ' ', preg_replace('/\s*\*+\/\s*$/', '', $tag[2]) ?? '') ?? '';
                $name = self::documentedName(trim($body));
                if ($name === null) {
                    $open = true;
                } else {
                    $names[] = $name;
                }
            }
        }

        return [$names, $open];
    }

    /**
     * The method a `@method` tag names, in the one form read with confidence:
     * `[static] [<type>] <name>(<params>)`, then at most a description with no
     * parenthesis. Exactly one parenthesised group may stand outside generics
     * and shapes, and it must follow the name directly. Null for anything else.
     */
    private static function documentedName(string $body): ?string
    {
        $length = strlen($body);
        $nesting = 0;
        $group = null;
        for ($at = 0; $at < $length; $at++) {
            $char = $body[$at];
            if ($char === '<' || $char === '{') {
                $nesting++;
            } elseif (($char === '>' || $char === '}') && $nesting > 0) {
                $nesting--;
            } elseif ($char === '(' && $nesting === 0) {
                $close = self::closingParenthesis($body, $at);
                if ($group !== null || $close === null) {
                    return null;
                }
                $group = $at;
                $at = $close;
            }
        }
        if ($group === null || preg_match('/^(?:static\s+)?(?:(.*\S)\s+)?([A-Za-z_]\w*)$/s', substr($body, 0, $group), $parts) !== 1) {
            return null;
        }
        if (in_array(strtolower($parts[2]), self::TYPE_WORDS, true)) {
            return null;
        }

        return $parts[1] === '' || self::isSingleType($parts[1]) ? $parts[2] : null;
    }

    /** Whether the text is one type: a name, generics and shapes allowed, joined by `|` or `&` and nothing else. */
    private static function isSingleType(string $type): bool
    {
        do {
            $before = $type;
            $type = preg_replace('/<[^<>]*>|\{[^{}]*\}/', '', $type) ?? '';
        } while ($type !== $before);
        $type = preg_replace('/\s*([|&])\s*/', '$1', $type) ?? '';

        return preg_match('/^[\w\\\\?|&\[\]]+$/', $type) === 1;
    }

    /** The offset of the parenthesis closing the one at `$open`, or null when the text ends first. */
    private static function closingParenthesis(string $text, int $open): ?int
    {
        $depth = 0;
        for ($at = $open, $length = strlen($text); $at < $length; $at++) {
            $depth += match ($text[$at]) {
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
