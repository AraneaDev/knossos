<?php

namespace App\Billing;

class Foo
{
    public function charge(int $cents, string $currency = 'EUR'): int
    {
        return $cents;
    }

    protected function audit(): void
    {
    }
}
