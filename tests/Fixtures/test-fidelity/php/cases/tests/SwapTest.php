<?php
namespace Tests;
use App\Calc;
use PHPUnit\Framework\TestCase;

function swap(&$c): void { $c = new Calc(); }

abstract class SwapBase extends TestCase
{
    protected $calc;
    protected function useReal(): void { $this->calc = new Calc(); }
}

final class InFileParentTest extends SwapBase
{
    protected function setUp(): void { $this->calc = $this->createStub(Calc::class); $this->calc->method('total')->willReturn(42); }
    public function testParentSwap(): void { $this->useReal(); $this->assertSame(42, $this->calc->total()); }
}

final class DestructSwapTest extends TestCase
{
    private $calc;
    protected function setUp(): void { $this->calc = $this->createStub(Calc::class); $this->calc->method('total')->willReturn(42); }
    public function testListSwap(): void { [$this->calc] = [new Calc()]; $this->assertSame(42, $this->calc->total()); }
}

final class ForeachSwapTest extends TestCase
{
    private $calc;
    protected function setUp(): void { $this->calc = $this->createStub(Calc::class); $this->calc->method('total')->willReturn(42); }
    public function testForeachSwap(): void { foreach ([new Calc()] as $this->calc) {} $this->assertSame(42, $this->calc->total()); }
}

final class RefSwapTest extends TestCase
{
    private $calc;
    protected function setUp(): void { $this->calc = $this->createStub(Calc::class); $this->calc->method('total')->willReturn(42); }
    public function testRefSwap(): void { $r = &$this->calc; $r = new Calc(); $this->assertSame(42, $this->calc->total()); }
}

final class ByRefArgSwapTest extends TestCase
{
    private $calc;
    protected function setUp(): void { $this->calc = $this->createStub(Calc::class); $this->calc->method('total')->willReturn(42); }
    public function testByRefSwap(): void { swap($this->calc); $this->assertSame(42, $this->calc->total()); }
}

final class AliasSwapTest extends TestCase
{
    private $calc;
    protected function setUp(): void { $this->calc = $this->createStub(Calc::class); $this->calc->method('total')->willReturn(42); }
    public function testAliasSwap(): void { $self = $this; $self->calc = new Calc(); $this->assertSame(42, $this->calc->total()); }
}

final class DynSwapTest extends TestCase
{
    private $calc;
    protected function setUp(): void { $this->calc = $this->createStub(Calc::class); $this->calc->method('total')->willReturn(42); }
    public function testDynSwap(): void { $this->{'calc'} = new Calc(); $this->assertSame(42, $this->calc->total()); }
}

final class SetUpTwiceTest extends TestCase
{
    private $calc;
    protected function setUp(): void { $this->calc = $this->createStub(Calc::class); $this->calc->method('total')->willReturn(42); $this->calc = new Calc(); }
    public function testSetUpTwice(): void { $this->assertSame(42, $this->calc->total()); }
}

final class BindSwapTest extends TestCase
{
    private $calc;
    protected function setUp(): void { $this->calc = $this->createStub(Calc::class); $this->calc->method('total')->willReturn(42); }
    public function testBindSwap(): void { \Closure::bind(function ($t) { $t->calc = new Calc(); }, null, self::class)($this); $this->assertSame(42, $this->calc->total()); }
}
