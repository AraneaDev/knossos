<?php

namespace App;

abstract class AbstractGateway
{
    abstract public function process(string $input): string;

    public function normalize(string $input): string
    {
        return trim($input);
    }
}
