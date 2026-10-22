<?php

declare(strict_types=1);

namespace Fixture;

final class Box
{
    public array $items = [];

    public static int $count = 0;
}

final class User
{
    public function f(Box $b): void
    {
        $x = $b->items;
        $b->items = [];
        Box::$count++;
        $fn = function (): void {
            // `$this` is this class, not the box.
            $this->items = [];
        };
    }
}
