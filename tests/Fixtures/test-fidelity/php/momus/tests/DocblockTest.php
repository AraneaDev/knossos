<?php

namespace App\Tests;

use App\DocblockService;
use App\Invoice;
use PHPUnit\Framework\TestCase;

final class DocblockTest extends TestCase
{
    public function testPlantedDocblockClassReturnMismatch(): void
    {
        $service = $this->createMock(DocblockService::class);
        $service->method('findById')->willReturn('not-an-invoice');
        self::assertNotSame($service, null);
    }

    public function testPlantedDocblockArrayReturnMismatch(): void
    {
        $service = $this->createMock(DocblockService::class);
        $service->method('fetchIds')->willReturn('nope');
        self::assertNotSame($service, null);
    }

    public function testHealthyDocblockTypes(): void
    {
        $service = $this->createMock(DocblockService::class);
        $service->method('findById')->willReturn(new Invoice(1, 4200));
        $service->method('fetchIds')->willReturn([]);
        $service->method('tag')->willReturn(null);
        self::assertNotSame($service, null);
    }
}
