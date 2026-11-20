<?php

declare(strict_types=1);

namespace Members;

/**
 * @method static Foo create()
 * @method array<int, Foo> list()
 * @method bare()
 * @method int|string union(int $a, array<int, string> $b)
 * @method static self make(
 *     int $a,
 * ) Builds one.
 */
class DocumentedForms {}

/** @method Closure(int): void foo() */
class CallableReturn {}

/** @method callable(int) getHandler() */
class CallableTypeBeforeName {}

/** @method callable(int)|null foo() */
class CallableUnionBeforeName {}

/** @method int foo returns bar() */
class ProseBeforeParameters {}

/** @method int a() @method int b() */
class TwoTagsOnOneLine {}

/** @phpstan-method int a() */
class PhpstanMethod {}

/** @psalm-method int a() */
class PsalmMethod {}

/** @psalm-mixin Widget */
class PsalmMixin {}

/** @phpstan-mixin Widget */
class PhpstanMixin {}

/**
 * @method static Foo create()
 * @method this is not a method line
 */
class Malformed {}

/** @mixin Widget */
class Forwarding {}

trait Source
{
    public function foo(): void {}

    public function plain(): void {}
}

class UsesAliases
{
    use Source {
        foo as bar;
        foo as protected hidden;
        plain as protected;
    }
}

enum Plain
{
    case One;
}

enum Backed: string
{
    case One = 'one';
}
