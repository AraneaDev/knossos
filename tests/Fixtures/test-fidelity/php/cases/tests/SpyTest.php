<?php

namespace Tests;

use App\Billing\Calculator;
use App\Billing\Gateway;
use Mockery;
use PHPUnit\Framework\TestCase;

final class SpyTest extends TestCase
{
    public function testAssertsAnUnconfiguredSpy(): void
    {
        $spy = Mockery::spy(Gateway::class);
        $spy->shouldHaveReceived('charge')->with(100);
    }

    public function testAssertsAStubbedSpy(): void
    {
        $spy = Mockery::spy(Gateway::class);
        $spy->shouldReceive('charge')->andReturn(true);
        $spy->shouldNotHaveReceived('charge', [100, 'EUR']);
    }

    public function testAssertsASpyProductionReaches(): void
    {
        $spy = Mockery::spy(Gateway::class);
        (new Calculator($spy))->total();
        $spy->shouldHaveReceived('charge');
    }
}
