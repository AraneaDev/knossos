<?php

namespace Tests;

use App\Billing\Calculator;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

final class FlowTest extends TestCase
{
    public function testFollowsHelpers(): void
    {
        Assert::assertSame($this->calculator(), $this->limit());
    }

    public function testEchoesARevealedProphecy(): void
    {
        $prophecy = $this->prophesize(Calculator::class);
        $prophecy->total()->willReturn(9);
        $calculator = $prophecy->reveal();
        assertSame(9, $calculator->total());
        assertSame(9, $prophecy->total());
    }

    public function testEchoesAFacade(): void
    {
        Cache::shouldReceive('get')->andReturn('cached');
        $this->assertSame('cached', Cache::get('key'));
    }

    public function testCountsAnInheritedRequest(): void
    {
        $response = $this->get('/totals');
        $this->assertTrue($response->isOk());
    }

    public function testDropsValuesAReferenceMayChange(): void
    {
        $rows = [3, 1];
        sort($rows);
        [$first] = [1];
        $first = 2;
        $limit = 1;
        $limit = 2;
        $total = 1;
        $add = function () use (&$total): void {};
        $kept = 1;
        $alias = &$kept;
        $item = 0;
        foreach ([1, 2] as $item) {
        }
        $this->assertSame([3, 1], $rows);
        $this->assertSame(1, $first);
        $this->assertSame(1, $limit);
        $this->assertSame(1, $total);
        $this->assertSame(1, $kept);
        $this->assertSame(0, $item);
    }

    /**
     * @testdox not a test on its own
     */
    public function documentedOnly(): void {}

    public function testIgnoresAFixtureClass(): void
    {
        $this->assertTrue((new LocalFixture())->ready());
    }

    private function calculator(): Calculator
    {
        return new Calculator();
    }

    private function limit(): int
    {
        return 42;
    }
}

final class LocalFixture
{
    public function ready(): bool
    {
        return true;
    }
}
