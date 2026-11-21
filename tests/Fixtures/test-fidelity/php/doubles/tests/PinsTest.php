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
        $configured = $this->createConfiguredMock(Foo::class, ['charge' => 4, $key => 5, '' => 6, 'not a name' => 7]);
        $partial = $this->createPartialMock(Foo::class, ['charge', $other]);
        $stub = $this->createStub(Foo::class);
        $abstract = $this->getMockForAbstractClass(Foo::class);
    }

    public function testAnswersThatAreNotLiterals(): void
    {
        $mock = $this->createMock(Foo::class);
        $mock->method('send')->willReturnCallback(function (string $to, bool $urgent = false, ...$rest) {
            return true;
        });
        $mock->method('send')->willReturnCallback(fn (int $only) => true);
        $mock->method('chain')->willReturnSelf();
        $mock->method('named')->willReturnCallback($this->answer(...));
        \Illuminate\Support\Facades\Cache::shouldReceive('remember')->andReturnSelf();
    }
}
