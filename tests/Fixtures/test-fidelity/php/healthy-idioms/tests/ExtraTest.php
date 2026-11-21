<?php

namespace Tests;

use App\Counts;
use App\Labels;
use App\Name;
use App\Plain;
use Mockery;
use PHPUnit\Framework\TestCase;

final class ExtraTest extends TestCase
{
    public function testStringable(): void
    {
        $named = Mockery::mock(Labels::class);
        $named->shouldReceive('label')->andReturn(new Name());
        $plain = Mockery::mock(Labels::class);
        $plain->shouldReceive('label')->andReturn(new Plain());
        $strict = $this->createMock(Labels::class);
        $strict->method('label')->willReturn(new Name());
        $this->assertSame('name', (new \App\Service($named, $plain, $strict))->run());
    }

    public function testTraitDouble(): void
    {
        $counts = $this->getMockForTrait(Counts::class);
        $counts->method('base')->willReturn(1);
        $counts->method('step')->willReturn(1);
        $counts->method('missing')->willReturn(1);
        $this->assertSame(2, (new \App\Service($counts))->run());
    }
}
