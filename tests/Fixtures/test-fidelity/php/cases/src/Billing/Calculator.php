<?php

namespace App\Billing;

class Calculator
{
    public function __construct(private ?Gateway $gateway = null) {}

    public static function make(): self
    {
        return new self();
    }

    public function total(): int
    {
        $this->gateway?->charge(42, 'EUR');

        return 42;
    }
}
