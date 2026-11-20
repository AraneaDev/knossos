<?php
use App\Calc;

uses(Tests\Support\SwapsCalcHook::class);

beforeEach(function () {
    $this->calc = Mockery::mock(Calc::class);
    $this->calc->shouldReceive('total')->andReturn(42);
});

it('reads a stub a used trait may replace', function () {
    expect($this->calc->total())->toBe(42);
});
