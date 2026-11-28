<?php

namespace App;

use App\FixturesEnum\Lane;

class Invoice
{
    public function __construct(public int $id, public Lane $lane, public string $label = '')
    {
    }
}
