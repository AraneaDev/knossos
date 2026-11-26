<?php

namespace FixturesEnum;

enum Lane: string
{
    case Fast = 'fast';
    case Slow = 'slow';
}

class LaneRecord
{
    public string $id;
    public Lane $lane;
}
