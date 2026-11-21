<?php

namespace Experiments\Pest;

class FeatureFlag
{
    public function enabled(string $name): bool
    {
        return $name !== '';
    }
}
