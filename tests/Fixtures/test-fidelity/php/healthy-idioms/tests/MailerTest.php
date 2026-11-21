<?php

namespace Tests;

use App\Mailer;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;

final class MailerTest extends TestCase
{
    public function testMatchers(): void
    {
        $m = Mockery::mock(Mailer::class);
        $m->shouldReceive('send')->with('a', Mockery::andAnyOtherArgs())->andReturn(true);
        $m->shouldReceive('send')->with(Mockery::anyArgs())->andReturn(true);
        $m->shouldReceive('send')->with('a', 'b', [], Mockery::andAnyOthers())->andReturn(true);
        $m->shouldReceive('send')->withAnyArgs()->andReturn(true);
        $m->shouldReceive('send')->with(...['a', 'b'])->andReturn(true);
        $m->shouldReceive('send')->withArgs(['a', 'b'])->andReturn(true);
        $m->shouldReceive('send')->withArgs(fn ($to) => true)->andReturn(true);
        $m->shouldReceive('send')->with(\anyArgs())->andReturn(true);
        $m->shouldReceive('send')->with('a', \andAnyOtherArgs())->andReturn(true);
        $m->shouldReceive('ping')->with(Mockery::noArgs())->andReturn(true);
        $m->shouldReceive('self')->with('x')->andReturnSelf();
        $m->shouldReceive('done')->andReturnSelf();
        $m->shouldReceive('done')->andReturn();
        $p = $this->prophesize(Mailer::class);
        $p->send(Argument::any(), Argument::cetera())->willReturn(true);
        $p->send('a', 'b')->willReturn(true);
        $u = $this->createMock(Mailer::class);
        $u->method('send')->with('a')->willReturn(true);
        $u->method('done')->willReturnSelf();
        $u->method('ping')->willReturn(true, false);
        $this->assertTrue((new \App\Svc($m, $p->reveal(), $u))->run());
    }
}
