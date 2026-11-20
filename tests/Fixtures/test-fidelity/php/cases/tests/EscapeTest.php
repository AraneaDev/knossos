<?php
namespace Tests;
use PHPUnit\Framework\TestCase;
use Tests\Support\Records;
final class EscapeTest extends TestCase
{
    use Records;
    private array $events;
    protected function setUp(): void
    {
        $this->events = [];
    }
    public function testACallbackCallsATraitMethod(): void
    {
        (new \App\Sut())->on(function () { $this->events; });
        (new \App\Sut())->fire();
        $this->assertSame([], $this->events);
    }
}
final class InheritedCallTest extends TestCase
{
    use Records;
    private array $events;
    protected function setUp(): void
    {
        $this->events = [];
    }
    public function testCallsATraitMethod(): void
    {
        $this->record('e');
        $this->assertSame([], $this->events);
    }
}
final class ComputedNameTest extends TestCase
{
    private bool $seen;
    protected function setUp(): void
    {
        $this->seen = false;
    }
    public function testWritesAComputedName(): void
    {
        $name = 'seen';
        $this->{$name} = true;
        $this->assertSame(false, $this->seen);
    }
}
final class KeptTest extends TestCase
{
    private int $limit;
    protected function setUp(): void
    {
        $this->limit = 3;
    }
    public function testKeepsItsLimit(): void
    {
        $this->helper();
        $this->assertSame(3, $this->limit);
    }
    private function helper(): void {}
}
