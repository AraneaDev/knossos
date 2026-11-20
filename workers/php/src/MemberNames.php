<?php

declare(strict_types=1);

namespace KnossosPhpScanner;

use PhpParser\Node\Stmt;

/**
 * The method names a class-like declares itself, for the test-fidelity rules:
 * its own methods plus the ones its docblock gives it with `@method`.
 *
 * Inherited names stay with the parent; the graph walks the inheritance edges
 * to find them. A class answering `__call` or `__callStatic` accepts any name,
 * so its list is open.
 */
final class MemberNames
{
    /**
     * The sorted names a class-like declares or documents, and the open flag
     * when it answers any name.
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
        $doc = $node->getDocComment();
        if ($doc !== null) {
            preg_match_all('/@method\s+(?:static\s+)?(?:[^()\r\n]*\s)?(\w+)\s*\(/', $doc->getText(), $matches);
            foreach ($matches[1] as $name) {
                $names[$name] = true;
            }
        }
        $sorted = array_map('strval', array_keys($names));
        sort($sorted, SORT_STRING);

        return ['member_names' => $sorted, ...($open ? ['member_names_open' => true] : [])];
    }
}
