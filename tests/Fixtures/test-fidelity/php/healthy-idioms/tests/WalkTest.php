<?php

namespace Tests;

use App\Repo;
use App\Service;
use PHPUnit\Framework\TestCase;

final class WalkTest extends TestCase
{
    public function testWalkPassesExtra(): void
    {
        $a = $this->createMock(Repo::class);
        $b = $this->createStub(Repo::class);
        $a->expects($this->once())->method('save')->with('v', 0, 7)->willReturn(true);
        (new Service($a, $b))->each(['v']);
    }

    public function testADirectCallTakesNoExtra(): void
    {
        $store = $this->createMock(\App\Store::class);
        $store->expects($this->once())->method('save')->with('v', 0, 7)->willReturn(true);
        (new \App\Shelf($store))->put('v');
    }

    /** A test that lists Store::save as a callable hands nothing to production: line 22 still reports. */
    public static function callables(): iterable
    {
        yield [\App\Store::class, 'save'];
    }

    public function testAListedCallableIsTheTestsOwn(): void
    {
        $this->assertIsCallable([\App\Store::class, 'save']);
    }
}
