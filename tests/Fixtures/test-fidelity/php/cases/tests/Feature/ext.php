<?php
use Tests\Support\PreHooks;

beforeEach(function () { $this->count = 0; });

it('uses an external helper', function () {
    bump();
    expect($this->count)->toBe(5);
});
