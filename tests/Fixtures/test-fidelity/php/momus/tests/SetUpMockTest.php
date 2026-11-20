<?php

namespace App\Tests;

use App\InvoiceRepository;
use PHPUnit\Framework\TestCase;

final class SetUpMockTest extends TestCase
{
    private $repo;

    protected function setUp(): void
    {
        $this->repo = $this->createMock(InvoiceRepository::class);
    }

    public function testPlantedStaleMemberViaProperty(): void
    {
        $this->repo->method('deleteById')->willReturn(null);
        self::assertNotSame($this->repo, null);
    }

    public function testPlantedEchoViaProperty(): void
    {
        $this->repo->method('findById')->willReturn(42);
        self::assertSame(42, $this->repo->findById(1));
    }

    public function testHealthyPropertyMember(): void
    {
        $this->repo->method('save')->willReturn(null);
        self::assertNotSame($this->repo, null);
    }
}
