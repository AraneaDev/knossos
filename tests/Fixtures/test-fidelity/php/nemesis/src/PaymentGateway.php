<?php

namespace App\Contracts;

interface PaymentGateway
{
    public function chargeToken(string $token, int $amountCents): int;

    public function refund(string $transactionId): string;
}
