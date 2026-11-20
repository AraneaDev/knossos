<?php

namespace Tests\Support;

use App\Billing\Gateway;

final class FakeGateway implements Gateway
{
    public function charge(int $cents, string $currency): bool
    {
        return true;
    }
}
