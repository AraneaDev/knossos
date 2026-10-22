<?php

declare(strict_types=1);

namespace KnossosPhpScanner;

use PhpParser\Node;

/**
 * The deepest AST the worker walks. PHP-Parser's parser is table-driven
 * (bounded native stack), but NameResolver, NodeFinder and the fact collectors
 * recurse with depth equal to AST depth. Deeply nested expressions
 * (`$x = ((((…))))`) would exhaust the native stack, an uncatchable fatal, so
 * every parse result is measured against this cap before anything walks it.
 * Real source nests far below it; only adversarial or generated input hits it.
 */
final class AstDepth
{
    public const MAX = 1000;

    /**
     * Measures the nesting with an explicit stack so the check itself cannot
     * recurse into the pathological tree it is guarding against.
     *
     * @param array<Node> $ast
     */
    public static function exceeds(array $ast, int $cap = self::MAX): bool
    {
        /** @var list<array{0: Node, 1: int}> $stack */
        $stack = [];
        foreach ($ast as $node) {
            $stack[] = [$node, 1];
        }
        while ($stack !== []) {
            [$node, $depth] = array_pop($stack);
            if ($depth > $cap) {
                return true;
            }
            foreach ($node->getSubNodeNames() as $name) {
                $child = $node->$name;
                if ($child instanceof Node) {
                    $stack[] = [$child, $depth + 1];
                } elseif (is_array($child)) {
                    foreach ($child as $item) {
                        if ($item instanceof Node) {
                            $stack[] = [$item, $depth + 1];
                        }
                    }
                }
            }
        }

        return false;
    }
}
