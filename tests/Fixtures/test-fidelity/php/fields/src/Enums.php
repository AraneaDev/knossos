<?php

namespace Fields;

enum Lane: string
{
    case Slow = 'slow';
    case Fast = 'fast';

    public const DEFAULT = self::Slow;
}

enum Code: int
{
    case Low = 1;
    case Minus = -2;
}

enum Suit
{
    case Hearts;
    case Spades;
}

enum Computed: string
{
    case A = 'a';
    case B = self::A->value . 'b';
}
