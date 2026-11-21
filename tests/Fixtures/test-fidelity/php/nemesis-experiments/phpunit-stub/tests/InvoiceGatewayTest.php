<?php

namespace Experiments\PhpUnit\Tests;

use Experiments\PhpUnit\InvoiceGateway;
use PHPUnit\Framework\TestCase;

final class InvoiceGatewayTest extends TestCase
{
    public function testStaleStub(): void
    {
        $gateway = $this->createStub(InvoiceGateway::class);
        $gateway->method('issueLegacy')->willReturn('invoice');
        $gateway->method('issue')->willReturn('invoice');
        self::assertNotNull($gateway);
    }
}
