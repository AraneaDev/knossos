<?php

declare(strict_types=1);

namespace Members;

/**
 * @method static Widget make(array $attributes = [])
 * @method string label()
 * @method array<int, string> tags(int $limit)
 */
class Widget extends Base
{
    public function zeta(): void {}

    private function alpha(): void {}
}

class Base
{
    public function inherited(): void {}
}

interface Shape
{
    public function area(): float;
}

trait Greets
{
    public function greet(): void {}
}

enum Suit
{
    case Hearts;

    public function label(): string
    {
        return 'x';
    }
}
