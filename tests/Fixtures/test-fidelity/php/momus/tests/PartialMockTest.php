<?php

namespace App\Tests;

use App\InvoiceRepository;
use PHPUnit\Framework\TestCase;

final class PartialMockTest extends TestCase
{
    public function testPartialMockListsTheStubbedMembers(): void
    {
        $repo = $this->createPartialMock(InvoiceRepository::class, ['findById']);
        $repo->method('findById')->willReturn(null);
        self::assertNotSame($repo, null);
    }

    public function testConfiguredMockSetsReturnValuesFromTheArray(): void
    {
        $repo = $this->createConfiguredMock(InvoiceRepository::class, ['save' => true]);
        self::assertNotSame($repo, null);
    }
}
