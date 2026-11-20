<?php

declare(strict_types=1);

namespace KnossosPhpScanner\TestFacts;

use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;

/**
 * The assertions of a test as the syntax states them: the matcher called,
 * the line it is called on, whether it expects the opposite, and the
 * expressions it compares.
 *
 * - PHPUnit's value assertions, on `$this`, `self::`, `static::`, the
 *   `Assert` class or as functions: the first two arguments, expected first.
 * - Pest's `expect($actual)->toBe($expected)`, through `->not` and from
 *   one matcher to the next; `->and($other)` starts a new subject.
 * - Mockery's `$spy->shouldHaveReceived('m')`: the spy itself.
 */
final class AssertionSyntax
{
    /** PHPUnit's assertions read, each true when it expects the opposite of what it compares. */
    private const PHPUNIT = [
        'assertsame' => ['assertSame', false],
        'assertequals' => ['assertEquals', false],
        'assertnotsame' => ['assertNotSame', true],
        'assertnotequals' => ['assertNotEquals', true],
        'asserttrue' => ['assertTrue', false],
        'assertfalse' => ['assertFalse', false],
        'assertnull' => ['assertNull', false],
        'assertcount' => ['assertCount', false],
        'assertinstanceof' => ['assertInstanceOf', false],
    ];

    /** Mockery's spy assertions, each true when it expects no such call. */
    private const SPY = ['shouldhavereceived' => ['shouldHaveReceived', false], 'shouldnothavereceived' => ['shouldNotHaveReceived', true]];

    /** Classes whose static assertions are PHPUnit's: the test case itself and `Assert`. */
    private const ASSERTING_CLASSES = ['self', 'static', 'parent', 'assert', 'testcase'];

    private function __construct() {}

    /**
     * The assertion an expression makes, or null for any other expression.
     *
     * @return array{api: string, line: int, negated: bool, operands: list<Expr>}|null
     */
    public static function of(Expr $call): ?array
    {
        return match (true) {
            $call instanceof Expr\MethodCall => self::methodCall($call),
            $call instanceof Expr\StaticCall => $call->class instanceof Name && in_array(strtolower($call->class->getLast()), self::ASSERTING_CLASSES, true) ? self::phpunit($call) : null,
            $call instanceof Expr\FuncCall => $call->name instanceof Name ? self::phpunit($call) : null,
            default => null,
        };
    }

    /** Whether a call only reads what it is handed: an assertion, Pest's `expect()`, or its `->and()`. */
    public static function reads(Expr\CallLike $call): bool
    {
        if (self::of($call) !== null) {
            return true;
        }
        if ($call instanceof Expr\FuncCall) {
            return $call->name instanceof Name && strtolower($call->name->getLast()) === 'expect';
        }

        return $call instanceof Expr\MethodCall && $call->name instanceof Identifier && $call->name->toLowerString() === 'and' && self::expected($call->var) !== null;
    }

    /**
     * A method call that asserts: PHPUnit's on `$this`, Mockery's on a spy, or a Pest matcher.
     *
     * @return array{api: string, line: int, negated: bool, operands: list<Expr>}|null
     */
    private static function methodCall(Expr\MethodCall $call): ?array
    {
        $name = $call->name instanceof Identifier ? $call->name->toLowerString() : null;
        if ($name === null) {
            return null;
        }
        if ($call->var instanceof Expr\Variable && $call->var->name === 'this') {
            return self::phpunit($call);
        }
        if (isset(self::SPY[$name])) {
            return ['api' => self::SPY[$name][0], 'line' => $call->getStartLine(), 'negated' => self::SPY[$name][1], 'operands' => [$call->var]];
        }
        if (!str_starts_with($name, 'to')) {
            return null;
        }
        $subject = self::expected($call->var);
        if ($subject === null) {
            return null;
        }
        $expected = $call->isFirstClassCallable() ? null : ($call->getArgs()[0]->value ?? null);

        return [
            'api' => $call->name->toString(),
            'line' => $call->getStartLine(),
            'negated' => $call->var instanceof Expr\PropertyFetch,
            'operands' => $expected === null ? [$subject] : [$subject, $expected],
        ];
    }

    /**
     * The value a Pest matcher is about: what `expect()` or the latest
     * `->and()` before it was given. Null when the chain is not Pest's, or
     * passes through a modifier other than `->not`, such as `->each`.
     */
    private static function expected(Expr $chain): ?Expr
    {
        $current = $chain;
        if ($current instanceof Expr\PropertyFetch) {
            if (!$current->name instanceof Identifier || $current->name->toLowerString() !== 'not') {
                return null;
            }
            $current = $current->var;
        }
        for (;;) {
            if ($current instanceof Expr\FuncCall) {
                return $current->name instanceof Name && strtolower($current->name->getLast()) === 'expect' ? self::firstArgument($current) : null;
            }
            if (!$current instanceof Expr\MethodCall || !$current->name instanceof Identifier) {
                return null;
            }
            $name = $current->name->toLowerString();
            if ($name === 'and') {
                return self::firstArgument($current);
            }
            if (!str_starts_with($name, 'to')) {
                return null;
            }
            $current = $current->var instanceof Expr\PropertyFetch ? $current->var->var : $current->var;
        }
    }

    /**
     * A PHPUnit assertion, by its name.
     *
     * @return array{api: string, line: int, negated: bool, operands: list<Expr>}|null
     */
    private static function phpunit(Expr\MethodCall|Expr\StaticCall|Expr\FuncCall $call): ?array
    {
        $name = match (true) {
            $call instanceof Expr\FuncCall => $call->name instanceof Name ? $call->name->getLast() : '',
            default => $call->name instanceof Identifier ? $call->name->toString() : '',
        };
        $known = self::PHPUNIT[strtolower($name)] ?? null;
        if ($known === null || $call->isFirstClassCallable()) {
            return null;
        }
        $operands = array_map(static fn($argument): Expr => $argument->value, array_slice($call->getArgs(), 0, 2));

        return ['api' => $known[0], 'line' => $call->getStartLine(), 'negated' => $known[1], 'operands' => array_values($operands)];
    }

    /** A call's first argument, or null. */
    private static function firstArgument(Expr\CallLike $call): ?Expr
    {
        return $call->isFirstClassCallable() ? null : ($call->getArgs()[0]->value ?? null);
    }
}
