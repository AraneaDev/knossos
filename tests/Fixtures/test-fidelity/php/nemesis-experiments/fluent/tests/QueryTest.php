<?php

namespace Experiments\Fluent\Tests;

use Experiments\Fluent\Order;
use Experiments\Fluent\Query;
use PHPUnit\Framework\TestCase;

final class QueryTest extends TestCase
{
    public function testStubsThatOutlivedTheirContract(): void
    {
        $query = $this->createMock(Query::class);
        // `where` stopped being fluent, so the chain it promises is gone.
        $query->method('where')->willReturnSelf();
        // `Random` was removed from the order enum.
        $query->method('direction')->willReturn(Order::Random);

        $this->assertNotNull($query);
    }
}
