<?php

declare(strict_types=1);

namespace KnossosPhpScanner\TestFacts;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;

/**
 * The calls that make a test double, and what each says about it.
 *
 * PHPUnit's `$this->createMock(Foo::class)` family and `getMockBuilder()`,
 * Mockery's `Mockery::mock()` and `Mockery::spy()` (under any alias), Pest's
 * `mock()`, Laravel's `$this->mock()`, `partialMock()` and `spy()`, and
 * Prophecy's `$this->prophesize()`. The class a double stands in
 * for is read from a `Foo::class` constant, resolved through the file's
 * namespace and imports, or from a string, which PHP always reads as fully
 * qualified. A variable or an expression names nothing the worker can know.
 */
final class DoubleFactories
{
    /** PHPUnit's factories on `$this`: the pattern each records and whether it is an automock. */
    private const PHPUNIT = [
        'createmock' => ['createMock', true],
        'createstub' => ['createStub', true],
        'createconfiguredmock' => ['createConfiguredMock', false],
        'createpartialmock' => ['createPartialMock', false],
        'getmockforabstractclass' => ['getMockForAbstractClass', false],
        'getmockfortrait' => ['getMockForTrait', false],
        'getmockbuilder' => ['getMockBuilder', false],
    ];

    /** Laravel's `InteractsWithContainer` helpers on the test case, which make a Mockery double and bind it in the container. */
    private const LARAVEL = ['mock' => 'laravel.mock', 'partialmock' => 'laravel.partialMock', 'spy' => 'laravel.spy'];

    /** Mockery's static factories, by the pattern each records. */
    private const MOCKERY = ['mock' => 'Mockery::mock', 'spy' => 'Mockery::spy'];

    /** A class name as PHP writes one, with an optional leading backslash. */
    private const CLASS_NAME = '/^\\\\?[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(?:\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*$/';

    private function __construct() {}

    /** The double a call makes, with the members its arguments name; null for a call that makes none. */
    public static function recognise(Expr $call): ?DoubleRecord
    {
        if ($call instanceof Expr\MethodCall && $call->var instanceof Expr\Variable && $call->var->name === 'this') {
            return self::onThis($call, self::lowerName($call->name));
        }
        if ($call instanceof Expr\StaticCall && $call->class instanceof Name) {
            $method = self::lowerName($call->name);
            if ($call->class->isSpecialClassName()) {
                return isset(self::PHPUNIT[$method]) ? self::onThis($call, $method) : null;
            }
            if (strtolower($call->class->getLast()) === 'mockery' && isset(self::MOCKERY[$method])) {
                return self::mockery($call, 'mockery', self::MOCKERY[$method]);
            }
        }
        if ($call instanceof Expr\FuncCall && $call->name instanceof Name && strtolower($call->name->getLast()) === 'mock') {
            return self::mockery($call, 'pest', 'pest.mock');
        }

        return null;
    }

    /**
     * The class whose facade a static `shouldReceive()` swaps for a Mockery
     * double, as Laravel's facades do; null for any other call.
     */
    public static function facade(Expr $call): ?string
    {
        if (!$call instanceof Expr\StaticCall || !$call->class instanceof Name || $call->class->isSpecialClassName() || self::lowerName($call->name) !== 'shouldreceive') {
            return null;
        }

        return strtolower($call->class->getLast()) === 'mockery' ? null : $call->class->toString();
    }

    /**
     * The closure a Mockery, Pest or Laravel factory hands the double it
     * makes, to configure it in: `Mockery::mock(Foo::class, fn ($m) => ...)`.
     */
    public static function configuringClosure(Expr\CallLike $call): Expr\Closure|Expr\ArrowFunction|null
    {
        if ($call->isFirstClassCallable()) {
            return null;
        }
        foreach ($call->getArgs() as $argument) {
            if ($argument->value instanceof Expr\Closure || $argument->value instanceof Expr\ArrowFunction) {
                return $argument->value;
            }
        }

        return null;
    }

    /** A PHPUnit or Prophecy factory called on the test case. */
    private static function onThis(Expr\MethodCall|Expr\StaticCall $call, string $method): ?DoubleRecord
    {
        $arguments = $call->isFirstClassCallable() ? [] : $call->getArgs();
        if ($method === 'prophesize') {
            return new DoubleRecord($call, 'prophecy', 'prophesize', self::className($arguments[0] ?? null), false);
        }
        if (isset(self::LARAVEL[$method]) && $call instanceof Expr\MethodCall) {
            return new DoubleRecord($call, 'mockery', self::LARAVEL[$method], self::className($arguments[0] ?? null), false);
        }
        if (!isset(self::PHPUNIT[$method])) {
            return null;
        }
        [$pattern, $automock] = self::PHPUNIT[$method];
        $double = new DoubleRecord($call, 'phpunit', $pattern, self::className($arguments[0] ?? null), $automock);
        $listed = $arguments[1]->value ?? null;
        if ($listed instanceof Expr\Array_ && $method === 'createconfiguredmock') {
            self::configured($double, $listed);
        } elseif ($listed instanceof Expr\Array_ && $method === 'createpartialmock') {
            self::listed($double, $listed, 'partial');
        }

        return $double;
    }

    /**
     * A Mockery or Pest factory, with the members a `'Foo[a,b]'` partial mock
     * names, and the further types it is made to implement: a type named
     * after the first (`mock(A::class, B::class)`), or after a comma in the
     * first (`mock('A, B')`). An argument that may name one but does not
     * show it (a variable, a call) leaves the further types open.
     */
    private static function mockery(Expr\StaticCall|Expr\FuncCall $call, string $framework, string $pattern): DoubleRecord
    {
        $arguments = $call->isFirstClassCallable() ? [] : array_values($call->getArgs());
        $first = $arguments[0] ?? null;
        $types = $first !== null && $first->value instanceof Scalar\String_ && str_contains($first->value->value, ',') && !str_contains($first->value->value, ']')
            ? array_map(static fn(string $type): ?string => self::className(new Arg(new Scalar\String_($type))), explode(',', $first->value->value))
            : [self::className($first)];
        foreach (array_slice($arguments, 1) as $argument) {
            $value = $argument->value;
            if ($argument->unpack || !($value instanceof Expr\Array_ || $value instanceof Expr\Closure || $value instanceof Expr\ArrowFunction)) {
                $types[] = self::className($argument);
            }
        }
        $double = new DoubleRecord($call, $framework, $pattern, $types[0], false);
        $also = array_slice($types, 1);
        $double->alsoImplements = in_array(null, $also, true) ? null : array_values(array_filter($also, is_string(...)));
        if ($first !== null && $first->value instanceof Scalar\String_ && preg_match('/\[([^\]]*)\]\s*$/', $first->value->value, $match) === 1) {
            foreach (explode(',', $match[1]) as $name) {
                $double->member(trim($name), $first->value->getStartLine(), 'partial');
            }
        }

        return $double;
    }

    /** Each string key of `createConfiguredMock()`'s array is a member, its value the return it pins. */
    private static function configured(DoubleRecord $double, Expr\Array_ $array): void
    {
        foreach ($array->items as $item) {
            if ($item->key instanceof Scalar\String_ && !$item->unpack && $double->member($item->key->value, $item->key->getStartLine(), 'configured')) {
                $double->pin($item->key->value, 'configured', $item->value, $item->value->getStartLine());
            }
        }
    }

    /** Each string in a list of method names is a member: `createPartialMock()`, `onlyMethods()`. */
    public static function listed(DoubleRecord $double, Expr\Array_ $array, string $api): void
    {
        foreach ($array->items as $item) {
            if ($item->value instanceof Scalar\String_ && $item->key === null) {
                $double->member($item->value->value, $item->value->getStartLine(), $api);
            }
        }
    }

    /**
     * The fully qualified class an argument names, or null when it names none
     * the syntax shows: `Foo::class`, or a string such as `'App\Foo'`, less
     * Mockery's `alias:` or `overload:` prefix and `[a,b]` member list.
     */
    private static function className(?Arg $argument): ?string
    {
        $value = $argument?->value;
        if ($argument === null || $argument->unpack) {
            return null;
        }
        if ($value instanceof Expr\ClassConstFetch && $value->class instanceof Name && !$value->class->isSpecialClassName() && self::lowerName($value->name) === 'class') {
            return $value->class->toString();
        }
        if (!$value instanceof Scalar\String_) {
            return null;
        }
        $name = (string) preg_replace(['/^(?:alias|overload):/', '/\[[^\]]*\]\s*$/'], '', trim($value->value));

        return preg_match(self::CLASS_NAME, $name) === 1 ? ltrim($name, '\\') : null;
    }

    /** A call's or constant's name in lower case, or an empty string when it is computed. */
    private static function lowerName(Identifier|Expr|Name $name): string
    {
        return $name instanceof Identifier || $name instanceof Name ? strtolower($name->toString()) : '';
    }
}
