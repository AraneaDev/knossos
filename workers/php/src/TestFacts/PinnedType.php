<?php

declare(strict_types=1);

namespace KnossosPhpScanner\TestFacts;

use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;

/**
 * The type of a value a test pins a double's member to, as far as the
 * expression itself shows it.
 *
 * One of `int`, `float`, `string`, `bool`, `null`, `array`, `class:<FQCN>`,
 * `closure` or `unknown`. The worker has no type checker, so a variable, a
 * call or anything else whose type takes inference is `unknown`, and core
 * stays silent about it.
 */
final class PinnedType
{
    public const UNKNOWN = 'unknown';

    private function __construct() {}

    /** The pinned expression's type, `unknown` when the expression alone does not show it. */
    public static function of(Expr $value): string
    {
        return match (true) {
            $value instanceof Scalar\Int_, $value instanceof Expr\Cast\Int_, $value instanceof Scalar\MagicConst\Line => 'int',
            $value instanceof Scalar\Float_, $value instanceof Expr\Cast\Double => 'float',
            $value instanceof Scalar\String_, $value instanceof Scalar\InterpolatedString, $value instanceof Scalar\MagicConst,
            $value instanceof Expr\Cast\String_, $value instanceof Expr\BinaryOp\Concat, self::isClassName($value) => 'string',
            $value instanceof Expr\Cast\Bool_, $value instanceof Expr\BooleanNot => 'bool',
            $value instanceof Expr\Array_, $value instanceof Expr\Cast\Array_ => 'array',
            $value instanceof Expr\Closure, $value instanceof Expr\ArrowFunction => 'closure',
            $value instanceof Expr\ConstFetch => self::constant($value),
            $value instanceof Expr\New_ => self::instance($value),
            $value instanceof Expr\UnaryMinus, $value instanceof Expr\UnaryPlus => self::signed($value->expr),
            default => self::UNKNOWN,
        };
    }

    /** `true`, `false` and `null` in any case; any other constant's value is not in the syntax. */
    private static function constant(Expr\ConstFetch $value): string
    {
        return match (strtolower($value->name->toString())) {
            'true', 'false' => 'bool',
            'null' => 'null',
            default => self::UNKNOWN,
        };
    }

    /** `new Foo()` is a `Foo`; `new self`, `new $class` and an anonymous class name nothing to check. */
    private static function instance(Expr\New_ $value): string
    {
        if (!$value->class instanceof Name || $value->class->isSpecialClassName()) {
            return self::UNKNOWN;
        }

        return 'class:' . $value->class->toString();
    }

    /** `-3` is an int and `-1.5` a float; a sign on anything else hides its type. */
    private static function signed(Expr $operand): string
    {
        return match (true) {
            $operand instanceof Scalar\Int_ => 'int',
            $operand instanceof Scalar\Float_ => 'float',
            default => self::UNKNOWN,
        };
    }

    /** Whether the value is `Foo::class`, which is a string. */
    private static function isClassName(Expr $value): bool
    {
        return $value instanceof Expr\ClassConstFetch && $value->name instanceof \PhpParser\Node\Identifier && strtolower($value->name->toString()) === 'class';
    }
}
