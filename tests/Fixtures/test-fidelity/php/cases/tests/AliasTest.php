<?php
namespace Tests;
use App\Sut;
use PHPUnit\Framework\TestCase;

final class AliasTest extends TestCase
{
    private $seen;
    private $events;
    private $hits;
    private $log;

    protected function setUp(): void
    {
        $this->seen = false;
        $this->events = [];
        $this->hits = 0;
        $this->log = [];
    }

    public function testSelfAlias(): void
    {
        $self = $this;
        (new Sut())->on(function () use ($self) { $self->seen = true; });
        (new Sut())->fire();
        $this->assertSame(true, $this->seen);
    }

    public function testAnonClass(): void
    {
        (new Sut())->on(new class($this) {
            public function __construct(private $t) {}
            public function __invoke() { $this->t->events[] = 'e'; }
        });
        (new Sut())->fire();
        $this->assertSame(['e'], $this->events);
    }

    public function testThisPassed(): void
    {
        (new Sut())->on($this);
        (new Sut())->fire();
        $this->assertSame(1, $this->hits);
    }

    public function __invoke(): void
    {
        ++$this->hits;
    }

    public function testRefToProperty(): void
    {
        $log = &$this->log;
        (new Sut())->on(function () use (&$log) { $log[] = 'x'; });
        (new Sut())->fire();
        $this->assertSame(['x'], $this->log);
    }

    public function testForeachThisProp(): void
    {
        foreach ($this->events as &$e) { $e = 'f'; }
        $this->assertSame([], $this->events);
    }

    public function testGlobalsArray(): void
    {
        $GLOBALS['flag'] = 1;
        $flag = 1;
        (new Sut())->fire();
        $this->assertSame(1, $flag);
    }
}
