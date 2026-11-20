<?php
namespace Tests;
use App\Sut;
use App\Repo;
use PHPUnit\Framework\TestCase;

final class ProbeTest extends TestCase
{
    private $got;
    private static $count = 0;
    private $repo;
    private $listened;

    protected function setUp(): void
    {
        $this->listened = null;
        $this->repo = $this->createStub(Repo::class);
        $this->repo->method('total')->willReturn(5);
    }

    public function testForeachByRef(): void
    {
        $items = [1, 2];
        foreach ($items as &$i) { $i *= 2; }
        unset($i);
        $this->assertSame([2, 4], $items);
    }

    public function testForeachReassign(): void
    {
        $sum = 0;
        foreach ((new Sut())->rows() as $r) { $sum = $sum + $r; }
        $this->assertSame(0, $sum);
    }

    public function testListDestructure(): void
    {
        $a = 1;
        [$a, $b] = (new Sut())->rows();
        list('x' => $c) = (new Sut())->rows();
        $this->assertSame(1, $a);
        $this->assertSame(1, $c);
    }

    public function testSutGet(): void
    {
        $sut = new Sut();
        $x = $sut->get();
        $this->assertSame(1, $x);
    }

    public function testStatic(): void
    {
        self::$count = 1;
        (new Sut())->on(static function () { self::$count++; });
        $this->assertSame(2, self::$count);
        $this->assertSame(2, static::$count);
    }

    public function testClone(): void
    {
        $a = [1];
        $b = clone (new Sut());
        $this->assertSame([1], $a);
        $this->assertSame(1, $b->get());
    }

    public function testCallableMethod(): void
    {
        $sut = new Sut();
        $sut->on([$this, 'record']);
        $sut->on($this->record(...));
        $sut->fire();
        $this->assertSame('x', $this->got);
    }

    public function record($e): void
    {
        $this->got = $e;
    }

    public function testListenedInNamedMethod(): void
    {
        $sut = new Sut();
        $sut->on([$this, 'onIt']);
        $sut->fire();
        $this->assertSame('fired', $this->listened);
    }

    public function onIt(): void
    {
        $this->listened = 'fired';
    }

    public function testSelfAlias(): void
    {
        $self = $this;
        (new Sut())->on(function () use ($self) { $self->listened = 'y'; });
        $this->assertSame('y', $this->listened);
    }

    public function testAnonClass(): void
    {
        (new Sut())->on(new class($this) { public function __construct(private $t) {} public function __invoke() { $this->t->listened = 'z'; } });
        $this->assertSame('z', $this->listened);
    }

    public function testStubFromSetUp(): void
    {
        $this->assertSame(5, $this->repo->total());
    }

    public function testStubOverride(): void
    {
        $this->repo = $this->createStub(Repo::class);
        $this->assertSame(5, $this->repo->total());
    }

    public function testArrayRefVar(): void
    {
        $data = ['a'];
        $ref = &$data;
        $ref[] = 'b';
        $this->assertSame(['a'], $data);
    }

    public function testRefParam(): void
    {
        $data = [];
        (new Sut())->fill($data);
        $this->assertSame([], $data);
    }

    public function testPregMatch(): void
    {
        $m = [];
        preg_match('/a/', 'a', $m);
        $this->assertSame([], $m);
    }

    public function testExtract(): void
    {
        $x = 1;
        extract(['x' => 2]);
        $this->assertSame(2, $x);
    }

    public function testVarVar(): void
    {
        $x = 1;
        $n = 'x';
        $$n = 2;
        $this->assertSame(2, $x);
    }

    public function testArrayPush(): void
    {
        $arr = [1];
        array_push($arr, 2);
        $this->assertSame([1, 2], $arr);
    }

    public function testGlobal(): void
    {
        global $g;
        $g = 1;
        (new Sut())->fire();
        $this->assertSame(2, $g);
    }

    public function testStaticVar(): void
    {
        static $s = 1;
        $this->assertSame(1, $s);
    }

    public function testMessage(): void
    {
        $this->assertTrue(true, 'msg');
        $this->assertFalse(false, 'msg');
        $this->assertNull(null, 'msg');
        $this->assertNotNull(1, 'msg');
        $this->assertIsInt(1, 'msg');
        $this->assertEmpty([], 'msg');
        $this->assertCount(0, [], 'msg');
    }
}
