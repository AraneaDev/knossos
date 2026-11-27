<?php

namespace Tests;

use App\Clock;
use App\Stamp;
use PHPUnit\Framework\TestCase;

final class ClockTest extends TestCase
{
    public function testStamp(): void
    {
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('2020-01-01'));
        $this->assertSame('2020', (new Stamp($clock))->year());
    }

    public function testAPlainObjectIsNoDateTime(): void
    {
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn(new \stdClass());
        $this->assertSame('2020', (new Stamp($clock))->year());
    }
}
