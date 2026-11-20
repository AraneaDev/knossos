<?php

namespace Tests;

use App\Billing\Foo;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;

final class RevealedTest extends TestCase
{
    use ProphecyTrait;

    private $shared;

    private $local;

    protected function setUp(): void
    {
        $prophecy = $this->prophesize(Foo::class);
        $prophecy->charge(1)->willReturn(1);
        $this->shared = $prophecy->reveal();
    }

    public function testThroughAVariable(): void
    {
        $prophecy = $this->prophesize(Foo::class);
        $foo = $prophecy->reveal();
        $foo->callDirect(1, 2, 3);
    }

    public function testThroughAProperty(): void
    {
        $prophecy = $this->prophesize(Foo::class);
        $this->local = $prophecy->reveal();
        $this->local->callDirect(1, 2, 3);
    }

    public function testThroughASetUpProperty(): void
    {
        $this->shared->callDirect(1, 2, 3);
    }
}
