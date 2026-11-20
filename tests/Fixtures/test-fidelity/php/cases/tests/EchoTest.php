<?php

namespace Tests;

use App\Billing\Calculator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EchoTest extends TestCase
{
    public function testEchoesTheStub(): void
    {
        $stub = $this->createStub(Calculator::class);
        $stub->method('total')->willReturn(42);
        self::assertSame(42, $stub->total());
    }

    #[Test]
    public function echoesAKeptValue(): void
    {
        $expected = 7;
        $stub = $this->createStub(Calculator::class);
        $stub->method('total')->willReturn($expected);
        $this->assertEquals($expected, $stub->total());
    }

    /**
     * @test
     */
    public function comparesAChangedValue(): void
    {
        $value = 3;
        $value++;
        $this->assertNotSame($value, $value);
        $this->assertSame(4, $value);
    }

    public function helperIsNotATest(): void
    {
        $this->assertTrue(true);
    }
}
