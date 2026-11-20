<?php

namespace Tests;

use App\Billing\Foo;
use App\Invoice;
use PHPUnit\Framework\TestCase;

final class PinsTest extends TestCase
{
    public function testPinnedLiterals(): void
    {
        $x = 1;
        $mock = $this->createMock(Foo::class);
        $mock->method('a')->willReturn('nope');
        $mock->method('b')->willReturn(42);
        $mock->method('c')->willReturn(new Invoice());
        $mock->method('d')->willReturn($x);
        $mock->method('e')->willReturn(1.5, -3, true, null, [], fn () => 1);
        $mock->method('f')->willReturnCallback(fn () => 1);
        $mock->method('g')->willThrowException(new \RuntimeException());
    }

    public function testBuilderAndFactories(): void
    {
        $built = $this->getMockBuilder(Foo::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['charge'])
            ->addMethods(['added'])
            ->getMock();
        $built->method('charge')->willReturn(3);
        $configured = $this->createConfiguredMock(Foo::class, ['charge' => 4, $key => 5]);
        $partial = $this->createPartialMock(Foo::class, ['charge', $other]);
        $stub = $this->createStub(Foo::class);
        $abstract = $this->getMockForAbstractClass(Foo::class);
    }
}
