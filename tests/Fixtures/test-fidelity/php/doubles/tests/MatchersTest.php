<?php

namespace Tests;

use App\Billing\Foo;
use PHPUnit\Framework\TestCase;

final class MatchersTest extends TestCase
{
    public function testPhpunitWith(): void
    {
        $gateway = $this->createMock(Foo::class);
        $gateway->expects($this->once())->method('charge')->with(100, 'EUR', 'extra')->willReturn(7);
        $gateway->method('charge')->with(...$arguments);
    }

    public function testMockeryWith(): void
    {
        $mock = \Mockery::mock(Foo::class);
        $mock->shouldReceive('charge')->with(100)->andReturn(1);
        $mock->shouldReceive('charge')->withArgs([100, 'EUR']);
        $mock->shouldReceive('charge')->withArgs(fn (int $cents): bool => $cents > 0);
        $mock->shouldReceive('charge')->withArgs($matcher);
        $mock->shouldReceive('charge')->withNoArgs();
    }
}
