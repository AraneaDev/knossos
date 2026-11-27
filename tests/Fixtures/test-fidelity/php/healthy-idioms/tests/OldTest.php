<?php

namespace Tests;

use App\Repo;
use PHPUnit\Framework\TestCase;

final class OldTest extends TestCase
{
    public function testSetMethods(): void
    {
        $a = $this->getMockBuilder(Repo::class)->setMethods(['find', 'legacy'])->getMock();
        $a->method('legacy')->willReturn(3);
        $a->method('find')->willReturn('q');
        $this->assertSame('q', (new \App\Service($a, $a))->run() === 'qq' ? 'q' : 'n');
    }

    public function testMockeryArray(): void
    {
        $a = \Mockery::mock(Repo::class, ['find' => 'z']);
        $this->assertSame('zz', (new \App\Service($a, $a))->run());
    }

    public function testOnlyMethodsStillJudged(): void
    {
        $a = $this->getMockBuilder(Repo::class)->onlyMethods(['find', 'legacy'])->getMock();
        $a->method('find')->willReturn('q');
        $this->assertSame('qq', (new \App\Service($a, $a))->run());
    }
}
