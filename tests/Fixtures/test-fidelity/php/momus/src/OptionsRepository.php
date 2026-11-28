<?php

namespace App;

class OptionsRepository
{
    public function __construct(array $options = [], int $timeout = 30)
    {
    }

    public function fetchAll(): array
    {
        return [];
    }
}
