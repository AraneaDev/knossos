<?php

namespace Tests;

use App\Conn;
use App\Lease;
use App\Stmt;
use Mockery;
use PHPUnit\Framework\TestCase;

final class MoreTest extends TestCase
{
    public function testCallbackCapture(): void
    {
        $captured = null;
        $conn = $this->createMock(Conn::class);
        $conn->expects($this->once())->method('prepare')->with($this->callback(function (string $sql) use (&$captured): bool {
            $captured = $sql;
            return true;
        }))->willReturn($this->createStub(Stmt::class));
        (new Lease($conn))->release();
        $this->assertSame('x', $captured);
    }

    public function testMockeryUsing(): void
    {
        $n = 0;
        $conn = Mockery::mock(Conn::class);
        $conn->shouldReceive('prepare')->andReturnUsing(function () use (&$n) {
            $n++;
            return Mockery::mock(Stmt::class, ['execute' => true]);
        });
        (new Lease($conn))->release();
        $this->assertSame(1, $n);
    }

    public function testLaravelClosure(): void
    {
        $n = 0;
        $this->mock(Conn::class, function ($mock) use (&$n) {
            $mock->shouldReceive('prepare')->andReturnUsing(function () use (&$n) {
                $n++;
                return Mockery::mock(Stmt::class, ['execute' => true]);
            });
        });
        $this->app->make(Lease::class)->release();
        $this->assertSame(1, $n);
    }
}
