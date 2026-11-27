<?php

declare(strict_types=1);

namespace KnossosPhpScanner;

use Closure;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;

/**
 * The method a value hands on as a callable rather than calling it there:
 * `[$repo, 'save']`, `[Repo::class, 'save']`, `['App\Repo', 'save']` or
 * `'App\Repo::save'` (G3-31). Whatever the value is handed to may call the
 * method with more arguments than it takes, as `array_walk()` passes a
 * third, and PHP drops the extra ones silently.
 *
 * A class a string names must be qualified (`App\Repo`): a two-string list
 * of bare words (`['id', 'name']`) is far more often data than a callable.
 * A first-class callable (`$repo->save(...)`) is a call node, marked where
 * its `calls` edge is made.
 */
final class CallableReach
{
    /** A qualified class name, a leading separator allowed. */
    private const QUALIFIED_CLASS = '/^\\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+$/D';

    /** A method name. */
    private const METHOD = '/^[A-Za-z_][A-Za-z0-9_]*$/D';

    private function __construct() {}

    /**
     * `<FQCN>::<method>` for the method a node names as a callable value, or null when it names none.
     *
     * @param Closure(Expr): ?string $valueClass the class of an object the code holds, where the code shows it
     * @param Closure(Name): string $className a class name resolved as the file writes it
     */
    public static function method(Node $node, Closure $valueClass, Closure $className): ?string
    {
        if ($node instanceof Scalar\String_) {
            $parts = explode('::', $node->value);

            return count($parts) === 2 ? self::named(self::qualified($parts[0]), $parts[1]) : null;
        }
        if (!$node instanceof Expr\Array_ || count($node->items) !== 2) {
            return null;
        }
        [$receiver, $method] = $node->items;
        if ($receiver->key !== null || $method->key !== null || $receiver->unpack || !$method->value instanceof Scalar\String_) {
            return null;
        }

        return self::named(self::classOf($receiver->value, $valueClass, $className), $method->value->value);
    }

    /**
     * The class an array callable's first element names: `Repo::class`, a qualified string, or an object the code types.
     *
     * @param Closure(Expr): ?string $valueClass
     * @param Closure(Name): string $className
     */
    private static function classOf(Expr $receiver, Closure $valueClass, Closure $className): ?string
    {
        if ($receiver instanceof Expr\ClassConstFetch) {
            return $receiver->class instanceof Name && $receiver->name instanceof Identifier && strtolower($receiver->name->toString()) === 'class'
                ? $className($receiver->class)
                : null;
        }

        return $receiver instanceof Scalar\String_ ? self::qualified($receiver->value) : $valueClass($receiver);
    }

    /** A string's class name without its leading separator, when it is qualified; null otherwise. */
    private static function qualified(string $class): ?string
    {
        return preg_match(self::QUALIFIED_CLASS, $class) === 1 ? ltrim($class, '\\') : null;
    }

    /** `<class>::<method>`, or null when either is missing or the method is no name. */
    private static function named(?string $class, string $method): ?string
    {
        return $class === null || preg_match(self::METHOD, $method) !== 1 ? null : $class . '::' . $method;
    }
}
