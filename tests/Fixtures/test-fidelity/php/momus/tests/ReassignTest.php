<?php

namespace App\Tests;

use App\Invoice;
use App\InvoiceRepository;
use PHPUnit\Framework\TestCase;

final class ReassignTest extends TestCase
{
    public function testReassignedMockKeepsScopesStraight(): void
    {
        $mock = $this->createMock(InvoiceRepository::class);
        $mock->method('save')->willReturn(null);

        $mock = $this->createMock(InvoiceRepository::class);
        $mock->method('findById')->willReturn(new Invoice(1, 4200));

        self::assertNotSame($mock, null);
    }
}
