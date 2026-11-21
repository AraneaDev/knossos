<?php

it('adds', fn () => expect(1 + 1)->toBe(2));

beforeEach(function () {
    $this->limit = 3;
});

it('keeps its limit', function () {
    expect($this->limit)->toBe(3);
});
