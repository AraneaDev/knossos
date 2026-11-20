<?php

namespace App;

class InvoiceRepository
{
    public function __construct(string $dsn, int $timeout = 30)
    {
    }

    public function findById(int $id): Invoice
    {
        throw new \RuntimeException('not implemented');
    }

    public function save(Invoice $invoice): void
    {
    }
}
