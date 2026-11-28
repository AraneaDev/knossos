<?php

namespace Tests;

use App\Conn;
use App\Lease;
use App\Stmt;
use PHPUnit\Framework\TestCase;

final class ObjectStateTest extends TestCase
{
    public function testObjectProperty(): void
    {
        $state = new \stdClass();
        $state->n = 0;
        $conn = $this->createStub(Conn::class);
        $conn->method('prepare')->willReturnCallback(function () use ($state): Stmt {
            $state->n++;
            return $this->createStub(Stmt::class);
        });
        (new Lease($conn))->release();
        $this->assertSame(1, $state->n);
    }

    public function testArrayElement(): void
    {
        $seen = ['n' => 0];
        $conn = $this->createStub(Conn::class);
        $conn->method('prepare')->willReturnCallback(function () use (&$seen): Stmt {
            $seen['n']++;
            return $this->createStub(Stmt::class);
        });
        (new Lease($conn))->release();
        $this->assertSame(1, $seen['n']);
        $this->assertSame(['n' => 1], $seen);
    }

    public function testStaticCounter(): void
    {
        static $hits = 0;
        $conn = $this->createStub(Conn::class);
        $conn->method('prepare')->willReturnCallback(function () use (&$hits): Stmt {
            $hits++;
            return $this->createStub(Stmt::class);
        });
        (new Lease($conn))->release();
        $this->assertSame(1, $hits);
    }
}
