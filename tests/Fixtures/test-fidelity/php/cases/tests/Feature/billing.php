<?php

use App\Billing\Calculator;
use App\Billing\Gateway;

beforeEach(function () {
    $this->calculator = new Calculator();
    $this->gateway = mock(Gateway::class);
});

it('totals', function () {
    expect($this->calculator->total())->toBe(42);
});

test('echoes the stub', function () {
    $this->gateway->shouldReceive('charge')->andReturn(true);
    expect($this->gateway->charge(1, 'EUR'))->not->toBeFalse()->and(true)->toBe(true);
});

it('reads a dataset', function (int $cents) {
    expect($cents)->toBeInt();
})->with([1, 2]);

it('is pending');

it('keeps what it expects', function () {
    $expected = 42;
    expect($expected)->toBe(42);
});

it('sets a property', function () {
    $this->local = 5;
});

it('reads another test\'s property', function () {
    expect($this->local)->toBe(5);
});

beforeEach(function () {
    $this->gateway->shouldReceive('refund')->andReturn(false);
});

it('refunds', function () {
    expect($this->gateway->refund())->toBeFalse();
});
