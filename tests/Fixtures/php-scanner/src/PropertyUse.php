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

final class Counter
{
    public int $n = 0;

    public array $list = [];

    public function bump(): void
    {
        $this->n = $this->n + 1;
        $this->n += 1;
        [$this->list, $y] = [[], 1];
        list($this->n) = [3];
        $this->list['key'] = 1;
        $this->list[] = 2;
        $this->list['x']['y'] = 3;
        $this->list['a']++;
        $this->list['b'] .= 'x';
        unset($this->list);
        unset($this->list['k']);
    }
}
