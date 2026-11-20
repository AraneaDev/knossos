<?php

namespace Tests;

use App\Billing\Foo;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;

final class ProphecyTest extends TestCase
{
    use ProphecyTrait;

    public function testProphecy(): void
    {
        $foo = $this->prophesize(Foo::class);
        $foo->charge(100, 'EUR')->willReturn(5);
        $foo->charge(Argument::cetera())->willReturn('nope');
        $foo->audit()->shouldBeCalled();
        $revealed = $foo->reveal();
        $this->consume($revealed);
        $this->consume($foo->reveal());
    }

    private function consume(Foo $foo): void
    {
    }
}
