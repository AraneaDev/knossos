<?php

namespace Tests;

use App\Logger;
use App\MagicChild;
use App\Post;
use App\Primary;
use App\Second;
use App\UsesMagic;
use Mockery;
use PHPUnit\Framework\TestCase;

final class EdgeTest extends TestCase
{
    public function testDocblockAndMagic(): void
    {
        $post = $this->createMock(Post::class);
        $post->method('magicCount')->willReturn(3);
        $post->method('save')->willReturn(true);
        $m = $this->createMock(UsesMagic::class);
        $m->method('anything')->willReturn(1);
        $c = $this->createMock(MagicChild::class);
        $c->method('anything')->willReturn(1);
        $this->assertSame(3, $post->magicCount());
    }

    public function testFuncGetArgs(): void
    {
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())->method('log')->with('a', 'b');
        $logger->expects($this->once())->method('write')->with('a', 'b');
        $mockery = Mockery::mock(Logger::class);
        $mockery->shouldReceive('log')->with('a', 'b');
        $logger->expects($this->once())->method('count')->with('a', 'b');
        $logger->expects($this->once())->method('first')->with('a', 'b');
        $logger->expects($this->once())->method('nested')->with('a', 'b');
        (new \App\Service($logger))->run();
    }

    public function testMultipleInterfaces(): void
    {
        $both = Mockery::mock(Primary::class, Second::class);
        $both->shouldReceive('second')->andReturn(2);
        $str = Mockery::mock('App\Primary, App\Second');
        $str->shouldReceive('second')->andReturn(2);
        $partial = Mockery::mock('App\Primary[first]');
        $partial->shouldReceive('first')->andReturn(2);
        $overload = Mockery::mock('overload:App\Primary');
        $overload->shouldReceive('first')->andReturn(2);
        $missing = Mockery::mock(Primary::class, Second::class);
        $missing->shouldReceive('third')->andReturn(2);
        $this->assertSame(2, $both->second());
    }

    public function testAddMethods(): void
    {
        $p = $this->getMockBuilder(Primary::class)->addMethods(['extra'])->getMock();
        $p->method('extra')->willReturn(1);
        $this->assertSame(1, (new \App\Service($p))->run());
    }
}
