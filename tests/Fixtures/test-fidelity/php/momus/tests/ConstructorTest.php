<?php

namespace App\Tests;

use App\InvoiceRepository;
use App\OptionsRepository;
use PHPUnit\Framework\TestCase;

final class ConstructorTest extends TestCase
{
    public function testMissingOriginalConstructorArgs(): void
    {
        $missing = $this->getMockBuilder(InvoiceRepository::class)
            ->enableOriginalConstructor()
            ->getMock();
        self::assertNotSame($missing, null);
    }

    public function testSuppliedOriginalConstructorArgs(): void
    {
        $healthy = $this->getMockBuilder(InvoiceRepository::class)
            ->setConstructorArgs(['dsn'])
            ->enableOriginalConstructor()
            ->getMock();
        self::assertNotSame($healthy, null);
    }

    public function testOptionalConstructorParamsAreNotRequired(): void
    {
        $defaultsOnly = $this->getMockBuilder(OptionsRepository::class)
            ->enableOriginalConstructor()
            ->getMock();
        self::assertNotSame($defaultsOnly, null);
    }
}
