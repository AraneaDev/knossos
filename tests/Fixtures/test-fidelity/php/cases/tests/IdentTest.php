<?php
namespace Tests;
use App\Calc;
use PHPUnit\Framework\TestCase;

trait Noop {}

final class TraitIdentTest extends TestCase
{
    use Noop;
    private $calc;
    private $spy;
    protected function setUp(): void { $this->calc = $this->createStub(Calc::class); $this->calc->method('total')->willReturn(42); $this->spy = \Mockery::spy(Calc::class); }
    public function testMockOnly(): void { $this->assertSame($this->calc->total(), $this->calc->total()); }
    public function testSpy(): void { $this->spy->shouldHaveReceived('total'); }
    public function testEcho(): void { $this->assertSame(42, $this->calc->total()); }
}

final class MultiIdentTest extends TestCase
{
    private $spy;
    private $calc;
    protected function setUp(): void { $this->spy = \Mockery::spy(Calc::class); $this->calc = $this->createStub(Calc::class); }
    public function testSpy(): void { $this->spy->shouldHaveReceived('total'); }
    public function testMockOnly(): void { $this->assertSame($this->calc->total(), $this->spy->total()); }
    public function testOther(): void { $this->spy = \Mockery::spy(Calc::class); $this->calc = $this->createStub(Calc::class); $this->assertTrue(true); }
}
