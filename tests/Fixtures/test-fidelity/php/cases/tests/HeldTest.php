<?php
namespace Tests;
use App\Calc;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;

trait SwapsCalc
{
    #[Before(priority: -1)]
    protected function swapCalc(): void { $this->calc = new Calc(); }
}

final class HelperSwapTest extends TestCase
{
    private Calc $calc;
    protected function setUp(): void { $this->calc = $this->createStub(Calc::class); $this->calc->method('total')->willReturn(42); }
    private function useReal(): void { $this->calc = new Calc(); }
    public function testHelperSwap(): void { $this->useReal(); $this->assertSame(42, $this->calc->total()); }
    public function testClosureSwap(): void { (function () { $this->calc = new Calc(); })(); $this->assertSame(42, $this->calc->total()); }
    public function testLocalSwap(): void { $this->calc = new Calc(); $this->assertSame(42, $this->calc->total()); }
    public function testEcho(): void { $this->assertSame(42, $this->calc->total()); }
}

final class TraitSwapTest extends TestCase
{
    use SwapsCalc;
    private Calc $calc;
    protected function setUp(): void { $this->calc = $this->createStub(Calc::class); $this->calc->method('total')->willReturn(42); }
    public function testTraitSwap(): void { $this->assertSame(42, $this->calc->total()); }
}
