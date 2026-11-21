<?php

namespace Tests;

use App\Billing\Foo as Bar;
use Illuminate\Support\Facades\Cache;
use Mockery as m;
use PHPUnit\Framework\TestCase;

final class ResolutionTest extends TestCase
{
    public function testAliasesAndStrings(): void
    {
        $aliased = $this->createMock(Bar::class);
        $named = \Mockery::mock('App\Billing\Foo');
        $leading = m::spy('\App\Billing\Foo');
        $partial = \Mockery::mock('App\Billing\Foo[charge]');
        $protected = m::mock(Bar::class)->shouldAllowMockingProtectedMethods();
        $protected->shouldReceive('audit');
    }

    public function testFacade(): void
    {
        Cache::shouldReceive('get')->with('key')->andReturn('value');
        Cache::shouldReceive('put');
    }

    public function testComputedClassNames(): void
    {
        $class = Bar::class;
        $variable = $this->createMock($class);
        $computed = \Mockery::mock('App\\' . 'Billing\Foo');
        $none = \Mockery::mock();
    }

    public function testFurtherTypes(): void
    {
        $both = m::mock(Bar::class, \Countable::class);
        $listed = \Mockery::mock('App\Billing\Foo, \Countable , App\Billing\Payable');
        $built = m::mock(Bar::class, ['charge' => 1], fn ($mock) => $mock);
        $open = m::mock(Bar::class, $interface);
        $trait = $this->getMockForTrait(Bar::class);
    }
}
