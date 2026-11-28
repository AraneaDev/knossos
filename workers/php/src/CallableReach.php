<?php

declare(strict_types=1);

namespace KnossosPhpScanner;

use Closure;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;

/**
 * The methods a call is handed as callable values rather than calling them
 * there (G3-31): `array_walk($xs, [$repo, 'save'], 7)`. Whatever takes the
 * callable may call it with more arguments than it takes, as `array_walk()`
 * passes a third, and PHP drops the extra ones silently.
 *
 * Only an argument passed straight to a function or method call counts, not
 * one to `new` (an exception's message), nor a value returned, yielded (a
 * data provider's row) or assigned. An array callable, `[$repo, 'save']`,
 * `[Repo::class, 'save']` or `['App\Repo', 'save']`, counts as an argument
 * of any call. A string callable, `'App\Repo::save'`, counts only handed to
 * `Closure::fromCallable()` or to a PHP function known to take a callback
 * (`array_map()`, `usort()`, `call_user_func()` and the rest): a string
 * shaped like one elsewhere is more often text. A class a string names must
 * be qualified, as a list of two bare words (`['id', 'name']`) is more often
 * data. A first-class callable (`$repo->save(...)`) is a call node, marked
 * where its `calls` edge is made.
 */
final class CallableReach
{
    /** A qualified class name, a leading separator allowed. */
    private const QUALIFIED_CLASS = '/^\\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+$/D';

    /** A method name. */
    private const METHOD = '/^[A-Za-z_][A-Za-z0-9_]*$/D';

    /** PHP's own functions that take a callback, in lower case, which a string may name. */
    private const CALLBACK_FUNCTIONS = [
        'array_filter', 'array_map', 'array_reduce', 'array_walk', 'array_walk_recursive', 'array_find', 'array_find_key', 'array_any', 'array_all',
        'usort', 'uasort', 'uksort', 'array_udiff', 'array_udiff_assoc', 'array_udiff_uassoc', 'array_diff_uassoc', 'array_diff_ukey',
        'array_uintersect', 'array_uintersect_assoc', 'array_uintersect_uassoc', 'array_intersect_uassoc', 'array_intersect_ukey',
        'call_user_func', 'call_user_func_array', 'forward_static_call', 'forward_static_call_array', 'is_callable', 'iterator_apply',
        'preg_replace_callback', 'register_shutdown_function', 'register_tick_function', 'set_error_handler', 'set_exception_handler', 'spl_autoload_register',
    ];

    private function __construct() {}

    /**
     * Each method the call's arguments hand on as a callable, `<FQCN>::<method>`, with the argument that names it.
     *
     * @param Closure(Expr): ?string $valueClass the class of an object the code holds, where the code shows it
     * @param Closure(Name): string $className a class name resolved as the file writes it
     * @return list<array{string, Expr}>
     */
    public static function arguments(Expr\CallLike $call, Closure $valueClass, Closure $className): array
    {
        if ($call instanceof Expr\New_ || $call->isFirstClassCallable()) {
            return [];
        }
        $strings = self::takesStrings($call, $className);
        $methods = [];
        foreach ($call->getArgs() as $argument) {
            $value = $argument->value;
            $method = match (true) {
                $argument->unpack => null,
                $value instanceof Expr\Array_ => self::arrayMethod($value, $valueClass, $className),
                $value instanceof Scalar\String_ && $strings => self::stringMethod($value->value),
                default => null,
            };
            if ($method !== null) {
                $methods[] = [$method, $value];
            }
        }

        return $methods;
    }

    /** Whether a string argument of the call may name a callable: the call is `Closure::fromCallable()` or a PHP function known to take a callback. */
    private static function takesStrings(Expr\CallLike $call, Closure $className): bool
    {
        if ($call instanceof Expr\StaticCall) {
            return $call->class instanceof Name && strcasecmp(ltrim($className($call->class), '\\'), 'Closure') === 0
                && $call->name instanceof Identifier && strcasecmp($call->name->toString(), 'fromCallable') === 0;
        }

        return $call instanceof Expr\FuncCall && $call->name instanceof Name && in_array(strtolower(ltrim($call->name->toString(), '\\')), self::CALLBACK_FUNCTIONS, true);
    }

    /**
     * The method a two-element array callable names, or null.
     *
     * @param Closure(Expr): ?string $valueClass
     * @param Closure(Name): string $className
     */
    private static function arrayMethod(Expr\Array_ $array, Closure $valueClass, Closure $className): ?string
    {
        if (count($array->items) !== 2) {
            return null;
        }
        [$receiver, $method] = $array->items;
        if ($receiver->key !== null || $method->key !== null || $receiver->unpack || !$method->value instanceof Scalar\String_) {
            return null;
        }

        return self::named(self::classOf($receiver->value, $valueClass, $className), $method->value->value);
    }

    /** The method a `Class::method` string names, or null. */
    private static function stringMethod(string $value): ?string
    {
        $parts = explode('::', $value);

        return count($parts) === 2 ? self::named(self::qualified($parts[0]), $parts[1]) : null;
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
