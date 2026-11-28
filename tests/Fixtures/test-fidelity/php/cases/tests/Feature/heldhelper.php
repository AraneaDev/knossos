<?php
use App\Calc;

beforeEach(function () {
    $this->calc = Mockery::mock(Calc::class);
    $this->calc->shouldReceive('total')->andReturn(42);
});

function useRealCalc(): void { test()->calc = new Calc(); }

it('reads a stub a helper replaces through test()', function () {
    useRealCalc();
    expect($this->calc->total())->toBe(42);
});
