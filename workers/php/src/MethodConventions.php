<?php

declare(strict_types=1);

namespace KnossosPhpScanner;

use PhpParser\Node;
use PhpParser\Node\{Expr, Identifier, Name, Stmt};

/**
 * What a method's own declaration says about how it is reached: a framework
 * calling it by reflection or convention, or a supertype member it fulfils.
 * Each reads the method's syntax and attributes alone.
 */
final class MethodConventions
{
    /**
     * Whether JMS Serializer reads this method as a property: `@VirtualProperty`
     * in its docblock or `#[VirtualProperty]`. The serializer calls it by
     * reflection, and no code names it.
     *
     * @param list<string> $attributes
     */
    public static function isVirtualProperty(Stmt\ClassMethod $node, array $attributes): bool
    {
        foreach ($attributes as $attribute) {
            if (str_ends_with('\\' . $attribute, '\\VirtualProperty')) {
                return true;
            }
        }
        $comment = $node->getDocComment()?->getText() ?? '';

        return preg_match('/(?:^|[\s*])@(?:[A-Za-z_\\\\]+\\\\)?VirtualProperty\b/m', $comment) === 1;
    }

    /**
     * Whether Eloquent calls this method by convention: an accessor or mutator
     * declared as an `Attribute` return, or a scope marked `#[Scope]`. The
     * model reads both by reflection and no code names them. The older
     * `getXAttribute` and `scopeX` spellings are told apart by the model
     * chain, which one file cannot see.
     *
     * @param list<string> $returnTypes
     * @param list<string> $attributes
     */
    public static function isEloquentConvention(array $returnTypes, array $attributes): bool
    {
        $normalised = static fn(array $names): array => array_map(static fn(string $name): string => ltrim($name, '\\'), $names);

        return in_array('Illuminate\\Database\\Eloquent\\Casts\\Attribute', $normalised($returnTypes), true)
            || in_array('Illuminate\\Database\\Eloquent\\Attributes\\Scope', $normalised($attributes), true);
    }

    /**
     * Whether a method fulfils a member of a type its class extends or
     * implements, by the source's word or by the language's.
     *
     * `#[\Override]` is the source saying so, and PHP checks it. So is a
     * `parent::` call of the method's own name, which fails unless an
     * ancestor has that member. A built-in supertype (`JsonSerializable`,
     * `Countable`, `IteratorAggregate`) is described by the worker's own
     * runtime, so its members are known without loading anything. A
     * dependency's supertype is neither, and is left to the attribute and the
     * call: guessing from its name would hide a method that only happens to
     * share one.
     *
     * @param list<string> $attributes resolved attribute names
     * @param array{parent: ?string, interfaces: list<string>} $class
     */
    public static function overridesSupertypeMember(Stmt\ClassMethod $node, array $attributes, array $class): bool
    {
        if (in_array('Override', $attributes, true) || self::callsOwnParentMember($node)) {
            return true;
        }
        $method = $node->name->toString();
        foreach ([$class['parent'], ...$class['interfaces']] as $supertype) {
            if ($supertype === null || (!class_exists($supertype, false) && !interface_exists($supertype, false))) {
                continue;
            }
            $reflection = new \ReflectionClass($supertype);
            if ($reflection->isInternal() && $reflection->hasMethod($method)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the method's own body calls `parent::` by the method's name. A
     * closure, arrow function or nested class is code of its own, which may
     * never run, so its calls are not counted.
     */
    private static function callsOwnParentMember(Stmt\ClassMethod $node): bool
    {
        $name = $node->name->toLowerString();
        $pending = $node->stmts ?? [];
        while ($pending !== []) {
            $child = array_pop($pending);
            if ($child instanceof Expr\StaticCall
                && $child->class instanceof Name && $child->class->toLowerString() === 'parent'
                && $child->name instanceof Identifier && $child->name->toLowerString() === $name) {
                return true;
            }
            if ($child instanceof Expr\Closure || $child instanceof Expr\ArrowFunction || $child instanceof Stmt\ClassLike || $child instanceof Stmt\Function_) {
                continue;
            }
            foreach ($child->getSubNodeNames() as $subNode) {
                foreach (is_array($child->$subNode) ? $child->$subNode : [$child->$subNode] as $item) {
                    if ($item instanceof Node) {
                        $pending[] = $item;
                    }
                }
            }
        }

        return false;
    }
}
