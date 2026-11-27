<?php

declare(strict_types=1);

namespace KnossosPhpScanner;

use PhpParser\Node;
use PhpParser\Node\Expr\Variable;

/**
 * The signature of a function or method as its source writes it: parameter
 * names, the types declared on them, and the declared return type.
 *
 * Nothing is inferred and a docblock is not read: a parameter without a
 * declared type has no `type`. Class names are the resolved ones, since that
 * is what the rest of the graph calls them.
 */
final class Signature
{
    /** Parameters a signature lists at most; the rest are cut and it says so. */
    public const MAX_PARAMS = 12;

    /** Characters of a type's text a signature keeps at most. */
    public const MAX_TYPE_LENGTH = 80;

    /** PHP's functions that read the arguments a function was called with, beyond those it declares. */
    private const READS_ARGUMENTS = ['func_get_args', 'func_num_args', 'func_get_arg'];

    private function __construct() {}

    /**
     * The `signature` attribute of a function or method.
     *
     * `optional` marks a parameter with a default value, `variadic` one that
     * takes the rest, `by_ref` one taken by reference (`&$out`). A function whose own body reads its arguments as a list
     * (`func_get_args()`, `func_num_args()`, `func_get_arg()`) takes any
     * number past its parameters, which the signature's own `variadic` says.
     *
     * @return array{params: list<array{name: string, type?: string, optional?: true, variadic?: true, by_ref?: true}>, returns?: string, variadic?: true, truncated?: true}
     */
    public static function of(Node\FunctionLike $node): array
    {
        $truncated = count($node->getParams()) > self::MAX_PARAMS;
        $params = [];
        foreach (array_slice($node->getParams(), 0, self::MAX_PARAMS) as $param) {
            $entry = ['name' => $param->var instanceof Variable && is_string($param->var->name) ? $param->var->name : '?'];
            if ($param->type !== null) {
                $entry['type'] = self::type($param->type, $truncated);
            }
            if ($param->default !== null) {
                $entry['optional'] = true;
            }
            if ($param->variadic) {
                $entry['variadic'] = true;
            }
            if ($param->byRef) {
                $entry['by_ref'] = true;
            }
            $params[] = $entry;
        }
        $signature = ['params' => $params];
        if ($node->getReturnType() !== null) {
            $signature['returns'] = self::type($node->getReturnType(), $truncated);
        }
        if (self::readsArguments($node)) {
            $signature['variadic'] = true;
        }

        return $truncated ? [...$signature, 'truncated' => true] : $signature;
    }

    /**
     * Whether a function's own body calls `func_get_args()`, `func_num_args()`
     * or `func_get_arg()`: not a closure, function or class declared in it,
     * whose arguments those calls would read instead.
     */
    private static function readsArguments(Node\FunctionLike $function): bool
    {
        $pending = $function->getStmts() ?? [];
        while ($pending !== []) {
            $node = array_pop($pending);
            if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name && in_array($node->name->toLowerString(), self::READS_ARGUMENTS, true)) {
                return true;
            }
            if ($node instanceof Node\FunctionLike || $node instanceof Node\Stmt\ClassLike) {
                continue;
            }
            foreach ($node->getSubNodeNames() as $name) {
                $child = $node->{$name};
                if ($child instanceof Node) {
                    $pending[] = $child;
                } elseif (is_array($child)) {
                    foreach ($child as $item) {
                        if ($item instanceof Node) {
                            $pending[] = $item;
                        }
                    }
                }
            }
        }

        return false;
    }

    /** A declared type's text within the length kept; sets `$truncated` when it was cut. */
    private static function type(Node $type, bool &$truncated): string
    {
        $text = self::text($type);
        if (mb_strlen($text) <= self::MAX_TYPE_LENGTH) {
            return $text;
        }
        $truncated = true;

        return mb_substr($text, 0, self::MAX_TYPE_LENGTH - 1) . '…';
    }

    /** A declared type as PHP writes it: `?int`, `A|B`, `A&B`, `(A&B)|null`. */
    private static function text(Node $type): string
    {
        return match (true) {
            $type instanceof Node\NullableType => '?' . self::text($type->type),
            $type instanceof Node\UnionType => implode('|', array_map(
                static fn(Node $part): string => $part instanceof Node\IntersectionType ? '(' . self::text($part) . ')' : self::text($part),
                $type->types,
            )),
            $type instanceof Node\IntersectionType => implode('&', array_map(self::text(...), $type->types)),
            default => $type instanceof Node\Name || $type instanceof Node\Identifier ? $type->toString() : '?',
        };
    }
}
