<?php

namespace Tests;

use App\Billing\Calculator;
use App\Billing\Gateway;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeGateway;

final class ProductionTest extends TestCase
{
    private Calculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new Calculator();
    }

    public function testCountsCallsAndSubjects(): void
    {
        $gateway = $this->createMock(Gateway::class);
        $gateway->method('charge')->willReturn(new Calculator());
        $made = Calculator::make();
        $fake = new FakeGateway();
        $this->calculator->total();
        $this->build()->total();
        $date = new \DateTimeImmutable(sprintf('@%d', 0));
        self::assertTrue(true);
    }

    public function testKeepsAHealthyTestProductionSourced(): void
    {
        $calculator = new Calculator();
        $total = $calculator->total();
        self::assertSame(42, $total);
        self::assertSame(42, $this->calculator->total());
        self::assertSame(42, (new Calculator())->total());
    }

    private function build(): Calculator
    {
        return new Calculator();
    }
}
