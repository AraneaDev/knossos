<?php

namespace Tests;

use App\Billing\Foo;
use PHPUnit\Framework\TestCase;

final class PropertyScopeTest extends TestCase
{
    private $later;

    public function testMakesTheDouble(): void
    {
        $this->later->method('before')->willReturn(1);
        $this->later = $this->createMock(Foo::class);
        $this->later->method('first')->willReturn(1);
    }

    public function testAnotherMethod(): void
    {
        $this->later->method('second')->willReturn(1);
    }
}
