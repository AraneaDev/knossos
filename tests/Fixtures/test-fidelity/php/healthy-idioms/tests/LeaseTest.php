<?php

namespace Tests;

use App\Conn;
use App\Lease;
use App\Stmt;
use PHPUnit\Framework\TestCase;

use function PHPUnit\Framework\assertSame;

final class LeaseTest extends TestCase
{
    public function testCallbackCounter(): void
    {
        $count = 0;
        $conn = $this->createStub(Conn::class);
        $conn->method('prepare')->willReturnCallback(function (string $sql) use (&$count): Stmt {
            $count++;
            return $this->createStub(Stmt::class);
        });
        (new Lease($conn))->release();
        assertSame(1, $count);
    }

    public function testCallbackCounterMethodForm(): void
    {
        $count = 0;
        $conn = $this->createStub(Conn::class);
        $conn->method('prepare')->willReturnCallback(function (string $sql) use (&$count): Stmt {
            $count++;
            return $this->createStub(Stmt::class);
        });
        (new Lease($conn))->release();
        $this->assertSame(1, $count);
    }

    public function testPlainClosureCounter(): void
    {
        $count = 0;
        $tick = function () use (&$count): void {
            $count++;
        };
        $tick();
        $this->assertSame(1, $count);
    }

    public function testCollectorArray(): void
    {
        $calls = [];
        $conn = $this->createStub(Conn::class);
        $conn->method('prepare')->willReturnCallback(function (string $sql) use (&$calls): Stmt {
            $calls[] = $sql;
            return $this->createStub(Stmt::class);
        });
        (new Lease($conn))->release();
        $this->assertSame(['x'], $calls);
    }

    public function testArrowCounter(): void
    {
        $seen = new \ArrayObject();
        $conn = $this->createStub(Conn::class);
        $conn->method('prepare')->willReturnCallback(fn (string $sql) => $seen->append($sql) ?? $this->createStub(Stmt::class));
        (new Lease($conn))->release();
        $this->assertSame(1, $seen->count());
    }
}
