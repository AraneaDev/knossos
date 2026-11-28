<?php

namespace Experiments\Finality;

/** Sealing a class breaks every mock of it, which no signature check catches. */
final class Sealed
{
    public function run(int $times): bool
    {
        return $times > 0;
    }
}

class Partly
{
    public function __construct(private string $key = 'k')
    {
    }

    final public function locked(): bool
    {
        return true;
    }

    public static function build(): self
    {
        return new self();
    }
}
