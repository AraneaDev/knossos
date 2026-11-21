<?php

namespace App\Tests;

use App\InvoiceRepository;
use App\OptionsRepository;
use PHPUnit\Framework\TestCase;

final class Drift003Test extends TestCase
{
    public function testPlantedVoidReturnMismatch(): void
    {
        $repo = $this->createMock(InvoiceRepository::class);
        $repo->method('save')->willReturn(42);
        self::assertNotSame($repo, null);
    }

    public function testPlantedClassReturnMismatch(): void
    {
        $repo = $this->createMock(InvoiceRepository::class);
        $repo->method('findById')->willReturn('not-an-invoice');
        self::assertNotSame($repo, null);
    }

    public function testPlantedArrayReturnMismatch(): void
    {
        $options = $this->createMock(OptionsRepository::class);
        $options->method('fetchAll')->willReturn('nope');
        self::assertNotSame($options, null);
    }

    public function testHealthyReturnTypes(): void
    {
        $repo = $this->createMock(InvoiceRepository::class);
        $repo->method('findById')->willReturn(new Invoice(1, 4200));
        $options = $this->createMock(OptionsRepository::class);
        $options->method('fetchAll')->willReturn([]);
        self::assertNotSame($repo, null);
    }
}
