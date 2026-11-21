<?php

namespace Experiments\PhpUnit;

interface InvoiceGateway
{
    public function issue(string $number): int;

    private function rotateKey(): void;
}
