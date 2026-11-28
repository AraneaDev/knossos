<?php

namespace Tests;

use App\Dispatch;
use App\Leg;
use App\Router;
use App\Suit;
use PHPUnit\Framework\TestCase;

/** An enum parameter matched by its case, by every case `cases()` lists, by a case built from its value, and a pure enum's case (G3-19). */
final class RouteTest extends TestCase
{
    public function testABackedCase(): void
    {
        $router = $this->createMock(Router::class);
        $router->expects($this->once())->method('reroute')->with(Leg::Sea)->willReturn(true);
        $this->assertTrue((new Dispatch($router))->send(Leg::Sea));
    }

    public function testEveryCaseCasesLists(): void
    {
        $router = $this->createMock(Router::class);
        $router->method('reroute')->with($this->logicalOr(...Leg::cases()))->willReturn(true);
        $this->assertTrue((new Dispatch($router))->send(Leg::cases()[0]));
    }

    public function testACaseFromItsValue(): void
    {
        $router = $this->createMock(Router::class);
        $router->method('reroute')->with(Leg::from('air'))->willReturn(true);
        $this->assertTrue((new Dispatch($router))->send(Leg::Air));
    }

    public function testAPureCase(): void
    {
        $router = \Mockery::mock(Router::class);
        $router->shouldReceive('deal')->with(Suit::Hearts)->andReturn(true);
        $this->assertTrue((new Dispatch($router))->play(Suit::Hearts));
    }

    public function testARemovedCase(): void
    {
        $router = $this->createMock(Router::class);
        $router->method('reroute')->with('rail')->willReturn(true);
        $this->assertTrue((new Dispatch($router))->send(Leg::Air));
    }
}
