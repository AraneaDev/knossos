<?php

namespace App\Billing;

use PHPUnit\Framework\TestCase;

final class FooTest extends TestCase
{
    public function testBesideTheCode(): void
    {
        $foo = $this->createMock(Foo::class);
    }
}
