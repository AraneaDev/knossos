<?php

use Experiments\Pest\FeatureFlag;

it('experiments with a stale Pest mock', function (): void {
    $flag = mock(FeatureFlag::class);
    $flag->shouldReceive('isEnabled')->andReturn(true);
    expect($flag)->not->toBeNull();
});
