<?php
use Tests\Support\PreHooks;

uses(PreHooks::class);

beforeEach(function () { $this->x = 1; });

it('reads a trait hook value', function () {
    expect($this->x)->toBe(2);
});
