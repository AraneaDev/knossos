<?php

namespace Experiments\EnumValue;

enum Leg: string
{
    case Air = 'air';
    case Sea = 'sea';
}

class Shipment
{
    public function leg(): Leg
    {
        return Leg::Air;
    }

    public function reroute(Leg $to): bool
    {
        return $to !== Leg::Air;
    }
}
