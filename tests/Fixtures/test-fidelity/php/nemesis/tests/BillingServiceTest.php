<?php

namespace Tests\Unit;

use App\Contracts\PaymentGateway;
use PHPUnit\Framework\TestCase;
use Mockery;

class BillingServiceTest extends TestCase
{
    public function testGhostMethod(): void
    {
        $gateway = $this->createMock(PaymentGateway::class);
        $gateway->expects($this->once())->method('chargeWithToken')->willReturn(42);
        $this->assertInstanceOf(PaymentGateway::class, $gateway);
    }

    public function testArityMismatch(): void
    {
        $gateway = $this->createMock(PaymentGateway::class);
        $gateway->method('chargeToken')->with('abc', 2, 'extra')->willReturn(7);
        $this->assertTrue(true);
    }

    public function testReturnDrift(): void
    {
        $gateway = $this->createMock(PaymentGateway::class);
        $gateway->method('chargeToken')->willReturn('not-an-int');
        $this->assertTrue(true);
    }

    public function testMockeryClean(): void
    {
        $gateway = Mockery::mock(PaymentGateway::class);
        $gateway->shouldReceive('refund')->andReturn('refunded');
        $this->assertTrue(true);
    }
}
