<?php

namespace Experiments\Finality\Tests;

use Experiments\Finality\Partly;
use Experiments\Finality\Sealed;
use PHPUnit\Framework\TestCase;

final class SealedTest extends TestCase
{
    public function testDoublesThatCannotExist(): void
    {
        $sealed = $this->createMock(Sealed::class);
        $sealed->method('run')->willReturn(true);

        $partly = $this->createMock(Partly::class);
        $partly->method('locked')->willReturn(true);
        $partly->method('build')->willReturn(null);
        // A constructor is never routed through a double.
        $partly->method('__construct')->willReturn(null);

        $this->assertNotNull($sealed);
    }
}
