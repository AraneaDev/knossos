<?php
namespace Tests;
use PHPUnit\Framework\TestCase;
final class SharedTest extends TestCase
{
    private $stub;
    protected function setUp(): void
    {
        $this->stub = $this->createStub(\App\Calc::class);
        $this->stub->method('rate')->willReturn(5);
    }
    public function testA(): void
    {
        $this->stub->method('total')->willReturn(1);
        $this->assertNotNull($this->stub);
    }
    public function testC(): void
    {
        $this->assertSame(1, $this->stub->total());
    }
    public function testD(): void
    {
        $this->assertSame(5, $this->stub->rate());
    }
    public function testE(): void
    {
        $this->stub->method('rate')->willReturnMap([[1, 7]]);
        $this->assertSame(5, $this->stub->rate(1));
    }
}
