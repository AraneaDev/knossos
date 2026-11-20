<?php
use App\Sut;

beforeEach(function () {
    $this->count = 0;
    $this->items = [1, 2];
});

function bump(): void { test()->count = 5; }

it('bumps through test()', function () {
    bump();
    expect($this->count)->toBe(5);
});

it('foreach refs a property', function () {
    foreach ($this->items as &$i) { $i = 0; }
    expect($this->items)->toBe([0, 0]);
});
