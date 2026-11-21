<?php

$shared = 1;

beforeEach(function () use (&$shared) {
    $shared = 2;
});

test('sees beforeEach through a reference', function () use (&$shared) {
    expect($shared)->toBe(2);
});

test('nested destructuring', function () {
    $x = 1;
    [[$x]] = [[2]];
    expect($x)->toBe(2);
});
