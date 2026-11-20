<?php

namespace Tests;

use App\Billing\Foo;
use PHPUnit\Framework\TestCase;

final class DynamicTest extends TestCase
{
    public function testDynamicMemberNames(): void
    {
        $name = 'charge';
        $phpunit = $this->createMock(Foo::class);
        $phpunit->method($name)->willReturn(1);
        $mockery = \Mockery::mock(Foo::class);
        $mockery->shouldReceive($name)->andReturn(1);
        $prophecy = $this->prophesize(Foo::class);
        $prophecy->{$name}()->willReturn(1);
        $this->{$name} = $this->createMock(Foo::class);
        $this->{$name}->method('charge')->willReturn(1);
    }
}
