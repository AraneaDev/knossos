<?php

namespace App\Tests;

use App\AbstractGateway;
use PHPUnit\Framework\TestCase;

final class AbstractMockTest extends TestCase
{
    public function testPlantedStaleAbstractMember(): void
    {
        $mock = $this->getMockForAbstractClass(AbstractGateway::class);
        $mock->method('staleProcess')->willReturn('x');
        self::assertNotSame($mock, null);
    }

    public function testHealthyAbstractMember(): void
    {
        $mock = $this->getMockForAbstractClass(AbstractGateway::class);
        $mock->method('process')->willReturn('processed');
        self::assertNotSame($mock, null);
    }
}
