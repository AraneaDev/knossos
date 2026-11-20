<?php

use App\Billing\Foo;

it('charges', function () {
    $foo = mock(Foo::class);
    $foo->shouldReceive('charge')->andReturn(1);
});

it('audits', function () use ($foo) {
    $foo->shouldReceive('audit');
    $other = mock(Foo::class, function ($foo) {
        $foo->shouldReceive('refund');
    });
});
