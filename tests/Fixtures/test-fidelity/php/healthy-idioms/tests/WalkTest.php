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
}
