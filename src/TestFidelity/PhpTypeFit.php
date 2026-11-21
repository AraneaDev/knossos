<?php

declare(strict_types=1);

namespace Knossos\TestFidelity;

/**
 * Whether a value a PHP test pins fits the replaced method's declared return
 * type, judged in core because the PHP worker has no type checker.
 *
 * The pin's type is one the worker reads from the expression alone: `int`,
 * `float`, `string`, `bool`, `null`, `array`, `class:<FQCN>` or `closure`;
 * anything else is unknown. Scalars follow PHP's strict-types rules, where
 * only an `int` widens to `float`. `null` fits only a nullable type, a union
 * with `null`, or `mixed`; `void` and `never` take no pin. A class fits a type
 * it is or reaches through `extends` and `implements` in the scan; when a
 * relation could live outside the project (in `vendor/` or PHP itself) a
 * refusal is only a warning. `mixed`, an absent type, and whatever the
 * declared type leaves open (which `bool` a `true` return is, whether a
 * string names a callable) are unknown, and the rule is silent.
 *
 * A Mockery or Prophecy double's generated code is not strict-types, so PHP
 * coerces a scalar it returns (`'5'` for an `int`), turns a `Stringable`
 * object returned for `string` into a string, and a `void` method drops the
 * `null` it is given: for such a double those fits are unknown, as is a class
 * that may be `Stringable` against `string`. A PHPUnit double checks its
 * return value strictly and keeps the strict judgement.
 */
final class PhpTypeFit
{
    /** The pin fits. */
    private const FITS = 'fits';

    /** The pin does not fit, and both sides say so. */
    private const REFUSED = 'refused';

    /** The pin may not fit: the relation that would decide it lies outside the project. */
    private const DOUBTED = 'doubted';

    /** The pinned types the worker names, `class:<FQCN>` aside. */
    private const PINNED = ['int', 'float', 'string', 'bool', 'null', 'array', 'closure'];

    /** Built-in declared types, each with the pinned kinds it admits; a kind not listed is refused. */
    private const BUILTINS = [
        'int' => ['int'], 'float' => ['int', 'float'], 'string' => ['string'], 'bool' => ['bool'], 'null' => ['null'],
        'array' => ['array'], 'iterable' => ['array'], 'object' => ['class', 'closure'], 'callable' => ['closure'],
        'void' => [], 'never' => [], 'true' => [], 'false' => [],
    ];

    /** The scalar types PHP coerces one into another outside strict-types mode. */
    private const COERCED = ['int', 'float', 'string', 'bool'];

    /** Built-in declared types, each with the pinned kinds whose fit the type leaves open. */
    private const OPEN = ['true' => ['bool'], 'false' => ['bool'], 'callable' => ['string', 'array', 'class'], 'iterable' => ['class']];

    private function __construct() {}

    /**
     * Whether `$pinned` fits `$declared`: `fits` null when that is unknown,
     * and `typed` false when a refusal is only a warning.
     *
     * @param string $pinned the pin's type as the worker names it
     * @param string|null $declared the declared return type as the PHP worker writes it (`?int`, `A|B`, `(A&B)|null`)
     * @param string|null $self the class `self` and `static` stand for: the one declaring the method
     * @param bool $strict whether the double returns its value under strict types (PHPUnit), or lets PHP coerce it (Mockery, Prophecy)
     * @return array{fits: bool|null, typed: bool}
     */
    public static function judge(string $pinned, ?string $declared, TestFidelityGraph $graph, ?string $self = null, bool $strict = true): array
    {
        $pin = self::pin($pinned, $strict);
        $verdict = $pin === null || $declared === null || $declared === '' ? null : self::union($pin, $declared, $graph, $self);

        return match ($verdict) {
            self::FITS => ['fits' => true, 'typed' => true],
            self::REFUSED => ['fits' => false, 'typed' => true],
            self::DOUBTED => ['fits' => false, 'typed' => false],
            default => ['fits' => null, 'typed' => false],
        };
    }

    /**
     * The pinned kind, the class for `class:<FQCN>`, and whether the double
     * returns it strictly; null for a type outside the list.
     *
     * @return array{string, string, bool}|null
     */
    private static function pin(string $pinned, bool $strict): ?array
    {
        if (str_starts_with($pinned, 'class:')) {
            return strlen($pinned) > strlen('class:') ? ['class', substr($pinned, strlen('class:')), $strict] : null;
        }

        return in_array($pinned, self::PINNED, true) ? [$pinned, '', $strict] : null;
    }

    /**
     * A union fits when any member fits; else it is unknown when any member is, doubted when any is.
     *
     * @param array{string, string, bool} $pin
     */
    private static function union(array $pin, string $declared, TestFidelityGraph $graph, ?string $self): ?string
    {
        $members = str_starts_with($declared, '?') ? [substr($declared, 1), 'null'] : self::split($declared, '|');
        $verdicts = array_map(static fn(string $member): ?string => self::intersection($pin, trim($member, '()'), $graph, $self), $members);

        return match (true) {
            in_array(self::FITS, $verdicts, true) => self::FITS,
            in_array(null, $verdicts, true) => null,
            in_array(self::DOUBTED, $verdicts, true) => self::DOUBTED,
            default => self::REFUSED,
        };
    }

    /**
     * An intersection fits when every member fits; it is refused when any member is.
     *
     * @param array{string, string, bool} $pin
     */
    private static function intersection(array $pin, string $declared, TestFidelityGraph $graph, ?string $self): ?string
    {
        $verdicts = array_map(static fn(string $member): ?string => self::single($pin, $member, $graph, $self), explode('&', $declared));

        return match (true) {
            in_array(self::REFUSED, $verdicts, true) => self::REFUSED,
            in_array(null, $verdicts, true) => null,
            in_array(self::DOUBTED, $verdicts, true) => self::DOUBTED,
            default => self::FITS,
        };
    }

    /**
     * One named type: a built-in, `self` or `static`, or a class.
     *
     * @param array{string, string, bool} $pin
     */
    private static function single(array $pin, string $declared, TestFidelityGraph $graph, ?string $self): ?string
    {
        [$kind, $class, $strict] = $pin;
        $name = strtolower(ltrim($declared, '\\'));
        if ($name === 'self' || $name === 'static') {
            return $self === null ? null : self::single($pin, $self, $graph, null);
        }
        if (in_array($name, ['mixed', 'parent', '?', ''], true) || in_array($kind, self::OPEN[$name] ?? [], true)) {
            return null;
        }
        if (isset(self::BUILTINS[$name])) {
            return match (true) {
                in_array($kind, self::BUILTINS[$name], true) => self::FITS,
                !$strict && (self::coerces($kind, $name) || ($kind === 'class' && $name === 'string' && self::mayBeStringable($class, $graph))) => null,
                default => self::REFUSED,
            };
        }

        return match ($kind) {
            'class' => match ($graph->phpIsA($class, $declared)) {
                true => self::FITS,
                false => self::REFUSED,
                null => self::DOUBTED,
            },
            'closure' => $name === 'closure' ? self::FITS : self::REFUSED,
            default => self::REFUSED,
        };
    }

    /** Whether PHP outside strict-types mode may accept a `$kind` value for a declared `$name`: one scalar for another, or null for void. */
    private static function coerces(string $kind, string $name): bool
    {
        return (in_array($kind, self::COERCED, true) && in_array($name, self::COERCED, true)) || ($kind === 'null' && $name === 'void');
    }

    /**
     * Whether a class may turn into a string, as PHP outside strict-types
     * mode turns a `Stringable` one returned for `string`: it implements
     * declares or inherits `__toString()`, or the scan cannot show it does
     * not: a class outside the project, or one with an ancestor the scan does
     * not hold (PHP's own `Stringable` among them).
     */
    private static function mayBeStringable(string $class, TestFidelityGraph $graph): bool
    {
        $node = $graph->phpType($class);

        return $node === null || $graph->hasUnseenAncestor($node) || $graph->member($node, '__toString') !== null;
    }

    /**
     * A type's members at the top level, split on `$separator` outside parentheses.
     *
     * @return list<string>
     */
    private static function split(string $type, string $separator): array
    {
        $members = [];
        $depth = 0;
        $current = '';
        foreach (str_split($type) as $character) {
            $depth += match ($character) {
                '(' => 1,
                ')' => -1,
                default => 0,
            };
            if ($character === $separator && $depth === 0) {
                $members[] = $current;
                $current = '';
            } else {
                $current .= $character;
            }
        }
        $members[] = $current;

        return $members;
    }
}
