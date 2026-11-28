<?php

namespace App;

interface Clock
{
    public function now(): \DateTimeInterface;
}

final class Stamp
{
    public function __construct(private Clock $clock) {}

    public function year(): string
    {
        return $this->clock->now()->format('Y');
    }
}
