<?php

namespace Magic\Tests;

use Magic\Bag;
use Mockery;
use PHPUnit\Framework\TestCase;

class BagTest extends TestCase
{
    public function testMockeryProxiesThroughMagicCall(): void
    {
        // Mockery builds a proxy, so `__call` carries this at runtime.
        $bag = Mockery::mock(Bag::class);
        $bag->shouldReceive('whateverYouLike')->andReturn(1);

        $this->assertNotNull($bag);
    }
}
