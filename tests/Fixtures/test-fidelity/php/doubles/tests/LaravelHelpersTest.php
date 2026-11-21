<?php

namespace Tests;

use App\Billing\Foo;
use Mockery\MockInterface;
use Tests\TestCase;

final class LaravelHelpersTest extends TestCase
{
    public function testMock(): void
    {
        $this->mock(Foo::class, function (MockInterface $mock) {
            $mock->shouldReceive('charge')->with(100, 'EUR')->andReturn(5);
        });
    }

    public function testPartialMock(): void
    {
        $partial = $this->partialMock(Foo::class, fn (MockInterface $mock) => $mock->shouldReceive('audit')->andReturn(null));
        $partial->shouldReceive('charge')->with(1)->andReturn('nope');
    }

    public function testSpy(): void
    {
        $this->spy(Foo::class)->shouldReceive('charge')->withNoArgs()->andReturn(7);
    }
}
