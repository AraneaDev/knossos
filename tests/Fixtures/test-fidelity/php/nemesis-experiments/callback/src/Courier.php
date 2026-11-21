<?php

namespace Experiments\Callback;

class Courier
{
    public function send(string $to): bool
    {
        return $to !== '';
    }
}
