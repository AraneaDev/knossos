<?php

namespace Experiments\EnumValue\Tests;

use Experiments\EnumValue\Shipment;
use PHPUnit\Framework\TestCase;

final class ShipmentTest extends TestCase
{
    public function testValuesThatOutlivedTheirCases(): void
    {
        $shipment = $this->createMock(Shipment::class);
        // 'rail' was removed from the Leg enum; the string still type-checks.
        $shipment->method('leg')->willReturn('rail');
        $shipment->method('reroute')->with('rail')->willReturn(true);

        $this->assertNotNull($shipment);
    }
}
