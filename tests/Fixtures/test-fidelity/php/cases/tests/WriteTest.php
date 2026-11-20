<?php
namespace Tests;
use PHPUnit\Framework\TestCase;
final class WriteTest extends TestCase
{
    private bool $fired = false;
    public function testDim(): void
    {
        $items = [];
        $items[] = 'a';
        $this->assertSame(['a'], $items);
    }
    public function testUnset(): void
    {
        $map = ['a' => 1];
        unset($map['a']);
        $this->assertSame([], $map);
    }
    public function testCallback(): void
    {
        $sut = new \App\Dispatcher();
        $sut->listen(function () { $this->fired = true; });
        $sut->dispatch();
        $this->assertSame(true, $this->fired);
    }
    public function testMessage(): void
    {
        $this->assertTrue(true, 'placeholder');
    }
    public function testConsecutive(): void
    {
        $stub = $this->createStub(\App\Calc::class);
        $stub->method('next')->willReturnOnConsecutiveCalls(1, 2);
        $stub->next();
        $this->assertSame(1, $stub->next());
    }
    public function testMap(): void
    {
        $stub = $this->createStub(\App\Calc::class);
        $stub->method('get')->with('a')->willReturn('x');
        $stub->method('get')->with('b')->willReturn('y');
        $this->assertSame('x', $stub->get('b'));
    }
    /** @dataProvider rows */
    public function testProvider(int $n): void
    {
        $this->assertSame(1, $n);
    }
    public function testSetUpDim(): void
    {
        $this->list[] = 2;
        $this->assertSame([1], $this->list);
    }
    public function testLoopCollects(): void
    {
        $ids = [];
        foreach ((new \App\Dispatcher())->rows() as $row) {
            $ids[] = $row->id;
        }
        $this->assertSame([1, 2], $ids);
    }
    public function testArrowCallback(): void
    {
        $sut = new \App\Dispatcher();
        $sut->listen(fn () => $this->count = 3);
        $this->assertSame(0, $this->count);
    }
    public function testConstant(): void
    {
        $x = 1;
        $this->assertSame(1, $x);
    }
    public function testBareTrue(): void
    {
        $this->assertTrue(true);
        $this->assertNull(null, 'message');
        $this->assertCount(1, [1], 'message');
    }
    public function testMockeryValues(): void
    {
        $mock = \Mockery::mock(\App\Calc::class);
        $mock->shouldReceive('next')->andReturn(1, 2);
        $this->assertSame(1, $mock->next());
    }
    public function testOneGuardedAnswer(): void
    {
        $stub = $this->createStub(\App\Calc::class);
        $stub->method('get')->with('a')->willReturn('x');
        $stub->method('next')->willReturnOnConsecutiveCalls(1);
        $stub->method('all')->willReturn(...$rows);
        $this->assertSame('x', $stub->get('b'));
        $this->assertSame(1, $stub->next());
        $this->assertSame(1, $stub->all());
        $this->assertIsInt(1, 'message');
    }
    protected function setUp(): void
    {
        $this->list = [1];
        $this->count = 0;
    }
}
