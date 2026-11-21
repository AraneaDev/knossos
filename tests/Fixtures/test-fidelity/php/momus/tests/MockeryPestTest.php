<?php

namespace App\Tests;

use App\InvoiceRepository;
use PHPUnit\Framework\TestCase;

final class MockeryPestTest extends TestCase
{
    public function testMockeryContract(): void
    {
        $mockeryRepo = Mockery::mock(InvoiceRepository::class);
        $mockeryRepo->shouldReceive('fetchAll')->andReturn([]);
        self::assertNotSame($mockeryRepo, null);
    }

    public function testMockeryHealthyMember(): void
    {
        $spyRepo = Mockery::spy(InvoiceRepository::class);
        $spyRepo->shouldReceive('findById')->andReturn(new Invoice(1, 4200));
        self::assertNotSame($spyRepo, null);
    }

    public function testMockeryClosureFormHealthy(): void
    {
        $closureRepo = Mockery::mock(InvoiceRepository::class, fn ($m) => $m->shouldReceive('findById')->andReturn(new Invoice(1, 4200)));
        self::assertNotSame($closureRepo, null);
    }

    public function testMockeryClosureFormPlanted(): void
    {
        $closureStale = Mockery::mock('App\InvoiceRepository', function ($m) {
            $m->shouldReceive('fetchAll')->andReturn([]);
        });
        self::assertNotSame($closureStale, null);
    }
}

function pestMockFixture(): void
{
    $pestRepo = mock(InvoiceRepository::class);
    $pestRepo->shouldReceive('fetchAll')->andReturn([]);
}
