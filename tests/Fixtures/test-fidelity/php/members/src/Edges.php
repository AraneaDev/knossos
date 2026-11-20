<?php

declare(strict_types=1);

namespace Members;

/**
 * @method static Foo create()
 * @method array<int, Foo> list()
 * @method Closure(int): void foo()
 * @method bare()
 * @method int|string union(int $a, array<int, string> $b)
 */
class DocumentedForms {}

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
