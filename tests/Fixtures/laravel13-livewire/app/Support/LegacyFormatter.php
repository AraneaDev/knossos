<?php

namespace App\Support;

// The control: nothing uses this, and it must stay reported.
class LegacyFormatter
{
    public function format(string $value): string
    {
        return strtoupper($value);
    }
}
