<?php
namespace Tests;
use PHPUnit\Framework\TestCase;
use App\Sut;

final class ForeachRefTest extends TestCase
{
    private array $items;
    protected function setUp(): void { $this->items = [1, 2]; }
    public function testForeachRefProp(): void
    {
        foreach ($this->items as &$i) { $i *= 2; }
        $this->assertSame([2, 4], $this->items);
    }
}

final class DeclaredTest extends TestCase
{
    private array $items;
    private int $count;
    protected function setUp(): void { $this->items = [1]; $this->count = 0; }
    private function add(int $x): void { $this->items[] = $x; }
    private function bump(): void { $this->count = 5; }
    public function testDeclaredAppend(): void
    {
        $this->add(2);
        $this->assertSame([1, 2], $this->items);
    }
    public function testDeclaredAssign(): void
    {
        $this->bump();
        $this->assertSame(5, $this->count);
    }
}

final class DeclaredAssignOnlyTest extends TestCase
{
    private int $count;
    private function bump(): void { $this->count = 5; }
    public function testOnlyHelperAssigns(): void
    {
        $this->bump();
        $this->assertSame(5, $this->count);
    }
}

final class DeclaredRefTest extends TestCase
{
    private int $count;
    protected function setUp(): void { $this->count = 0; }
    private function viaRef(): void { $r = &$this->count; $r = 7; }
    public function testRef(): void
    {
        $this->viaRef();
        $this->assertSame(7, $this->count);
    }
}

abstract class BaseCase extends TestCase
{
    protected int $x;
    protected function setUp(): void { $this->x = 2; }
    protected function bumpX(): void { $this->x = 3; }
}

final class ChildTest extends BaseCase
{
    protected function setUp(): void { $this->x = 1; parent::setUp(); }
    public function testParentSetUpWins(): void
    {
        $this->assertSame(2, $this->x);
    }
}

final class ChildStaticCallTest extends BaseCase
{
    protected function setUp(): void { parent::setUp(); $this->x = 1; }
    public function testParentHelperViaParent(): void
    {
        parent::bumpX();
        $this->assertSame(3, $this->x);
    }
    public function testParentHelperViaStatic(): void
    {
        static::bumpX();
        $this->assertSame(3, $this->x);
    }
    public function testParentHelperViaSelf(): void
    {
        self::bumpX();
        $this->assertSame(3, $this->x);
    }
}

final class StaticTest extends TestCase
{
    private static int $count;
    public static function setUpBeforeClass(): void { self::$count = 0; }
    private static function bump(): void { self::$count = 1; }
    public function testStatic(): void
    {
        self::bump();
        $this->assertSame(1, self::$count);
        $this->assertSame(1, static::$count);
    }
}

final class LocalTest extends TestCase
{
    public function testArrayPush(): void
    {
        $x = [];
        array_push($x, 1);
        $this->assertSame([1], $x);
    }
    public function testSort(): void
    {
        $x = [2, 1];
        sort($x);
        $this->assertSame([1, 2], $x);
    }
    public function testNewByRef(): void
    {
        $x = [];
        new \App\Filler($x);
        $this->assertSame([1], $x);
    }
    public function testRefIntoProp(): void
    {
        $x = 1;
        $h = new \stdClass();
        $h->r = &$x;
        $h->r = 2;
        $this->assertSame(2, $x);
    }
    public function testStaticRef(): void
    {
        $x = 1;
        \App\Sut::$slot = &$x;
        (new Sut())->fire();
        $this->assertSame(2, $x);
    }
}

final class DestructureTest extends TestCase
{
    private int $a;
    private int $b;
    protected function setUp(): void { $this->a = 0; $this->b = 0; }
    public function testListIntoProp(): void
    {
        [$this->a, $this->b] = (new Sut())->rows();
        $this->assertSame(0, $this->a);
    }
    public function testListKeyedIntoProp(): void
    {
        list('k' => $this->b) = (new Sut())->rows();
        $this->assertSame(0, $this->b);
    }
    public function testListLocal(): void
    {
        $a = 0;
        [$a] = (new Sut())->rows();
        $this->assertSame(0, $a);
    }
    public function testNestedListIntoProp(): void
    {
        [[$this->a]] = (new Sut())->rows();
        $this->assertSame(0, $this->a);
    }
    public function testForeachListIntoProp(): void
    {
        foreach ((new Sut())->rows() as [$this->b]) {}
        $this->assertSame(0, $this->b);
    }
}
