<?php

namespace Tests;

use App\Conn;
use App\Labels;
use App\Lease;
use App\Primary;
use Mockery;
use Mockery as m;
use PHPUnit\Framework\TestCase;

final class CaptureTest extends TestCase
{
    public function testMockeryCapture(): void
    {
        $sql = null;
        $conn = Mockery::mock(Conn::class);
        $conn->shouldReceive('prepare')->with(Mockery::capture($sql));
        (new Lease($conn))->release();
        $this->assertSame('x', $sql);
    }

    public function testAliasedCaptureAfterOnce(): void
    {
        $sql = 'none';
        $conn = m::mock(Conn::class);
        $conn->shouldReceive('prepare')->once()->with(m::capture($sql));
        (new Lease($conn))->release();
        $this->assertSame('x', $sql);
    }

    public function testShiftInAChain(): void
    {
        $queue = ['a', 'b'];
        $labels = $this->createStub(Labels::class);
        $labels->method('label')->willReturn(array_shift($queue));
        (new \App\Service($labels))->run();
        $this->assertSame(['b'], $queue);
    }

    public function testIncrementInAChain(): void
    {
        $i = 0;
        $labels = $this->createStub(Labels::class);
        $labels->method('label')->willReturn((string) $i++);
        (new \App\Service($labels))->run();
        $this->assertSame(1, $i);
    }

    public function testAPinnedLocalStillEchoes(): void
    {
        $label = 'pinned';
        $labels = $this->createStub(Labels::class);
        $labels->method('label')->willReturn($label);
        $conn = $this->createStub(Conn::class);
        $conn->method('prepare')->with($label)->willReturn($this->createStub(\App\Stmt::class));
        (new Lease($conn))->release();
        $this->assertSame($label, $labels->label());
        $this->assertSame('pinned', $label);
    }

    public function testAddMethodsInAnyCase(): void
    {
        $p = $this->getMockBuilder(Primary::class)->addMethods(['Extra'])->getMock();
        $p->method('extra')->willReturn(1);
        $this->assertSame(1, (new \App\Service($p))->run());
    }
}
