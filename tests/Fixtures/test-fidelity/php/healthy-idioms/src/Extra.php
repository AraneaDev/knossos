<?php

namespace App;

class Name
{
    public function __toString(): string
    {
        return 'name';
    }
}

class Plain
{
}

class Labels
{
    public function label(): string
    {
        return '';
    }
}

trait Counts
{
    abstract public function base(): int;

    public function total(): int
    {
        return $this->base() + $this->step();
    }

    protected function step(): int
    {
        return 1;
    }
}
