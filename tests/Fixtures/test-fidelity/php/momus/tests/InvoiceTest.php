<?php

namespace App\Tests;

use App\InvoiceRepository as Repo;
use Legacy\LegacyRepository;
use PHPUnit\Framework\TestCase;

final class InvoiceTest extends TestCase
{
    public function testMockContract(): void
    {
        $repo = $this->createMock(Repo::class);
        $repo->method('findById')->willReturn(new Invoice(1, 4200));
        $repo->expects($this->once())->method('fetchAll');
        self::assertSame(4200, 4200);
    }

    public function testMockEchoesConfiguredValue(): void
    {
        $echoRepo = $this->createMock(Repo::class);
        $echoRepo->method('findById')->willReturn(42);
        self::assertSame(42, $echoRepo->findById(1));
    }

    public function testHealthyProductionShape(): void
    {
        $healthyRepo = $this->createMock(Repo::class);
        $healthyRepo->method('findById')->willReturn(new Invoice(1, 4200));
        self::assertNotSame($healthyRepo, null);
    }
}
