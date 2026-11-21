<?php
namespace Tests;
use App\Sut;
use App\Repo;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PinTest extends TestCase
{
    private $repo;
    private $m;
    private $value;

    protected function setUp(): void
    {
        $this->value = 3;
        $this->repo = $this->createStub(Repo::class);
        $this->m = Mockery::mock(Repo::class);
        $this->m->shouldReceive('total')->andReturn(1)->byDefault();
    }

    public static function rows(): array
    {
        return [[3], [4]];
    }

    #[DataProvider('rows')]
    public function testProviderAndSetUp(int $value): void
    {
        $this->repo->method('total')->willReturn($value);
        $this->assertSame($value, $this->repo->total());
        $this->assertSame(3, $this->value);
    }

    public function testHelperRepins(): void
    {
        $this->freeze(2);
        $this->assertSame(1, $this->m->total());
    }

    private function freeze(int $v): void
    {
        $this->m->shouldReceive('total')->andReturn($v);
    }

    public function testConsecutive(): void
    {
        $this->repo->method('find')->willReturnOnConsecutiveCalls(1, 2);
        $this->repo->find(1);
        $this->assertSame(1, $this->repo->find(1));
    }

    public function testWith(): void
    {
        $this->repo->method('find')->with('a')->willReturn('x');
        $this->assertSame('x', $this->repo->find('a'));
    }

    public function testCrossTest(): void
    {
        $this->repo->method('find')->willReturn('cross');
    }

    public function testCrossTestRead(): void
    {
        $this->assertSame('cross', $this->repo->find('a'));
    }

    public function testMapThenPlain(): void
    {
        $s = $this->createStub(Repo::class);
        $s->method('find')->willReturnMap([['a', 'x']]);
        $this->assertSame('x', $s->find('a'));
    }

    public function testOnceExhausted(): void
    {
        $s = Mockery::mock(Repo::class);
        $s->shouldReceive('find')->once()->andReturn('x');
        $s->shouldReceive('find')->andReturn('y');
        $this->assertSame('x', $s->find(1));
    }

    public function testMockeryWithAfterArgs(): void
    {
        $s = Mockery::mock(Repo::class);
        $s->allows()->find(1)->andReturns('x');
        $s->expects('find')->with(1)->andReturn('x');
        $this->assertSame('x', $s->find(1));
    }

    public function testExpectsArgs(): void
    {
        $s = Mockery::mock(Repo::class);
        $s->shouldReceive('find')->withArgs(fn($a) => $a === 1)->andReturn('x');
        $this->assertSame('x', $s->find(1));
    }

    public function testArrowPinGuardPrecedes(): void
    {
        $s = $this->createMock(Repo::class);
        $s->expects($this->once())->method('find')->with('a')->willReturn('x');
        $this->assertSame('x', $s->find('a'));
    }

    public function testVariablePinThenReassign(): void
    {
        $s = $this->createStub(Repo::class);
        $s->method('total')->willReturn(7);
        $s = $this->createStub(Repo::class);
        $this->assertSame(7, $s->total());
    }

    public function testTwoStubsSameVar(): void
    {
        $s = $this->createStub(Repo::class);
        $t = $s;
        $t->method('total')->willReturn(8);
        $this->assertSame(8, $s->total());
    }

    public function testForeachRefProperty(): void
    {
        $this->items = [1];
        foreach ($this->items as &$item) { $item = 2; }
        $this->assertSame([1], $this->items);
    }
}
