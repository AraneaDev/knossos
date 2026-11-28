<?php

namespace App\Billing;

interface Gateway
{
    public function charge(int $cents, string $currency): bool;
}
