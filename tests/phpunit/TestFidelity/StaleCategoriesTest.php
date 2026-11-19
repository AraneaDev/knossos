<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity;

use Knossos\TestFidelity\FindingCategory;
use Knossos\TestFidelity\StaleCategories;
use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

#[Group('test-fidelity')]
final class StaleCategoriesTest extends KnossosTestCase
{
    public function testADoubleThatNoLongerMatchesItsTargetIsStale(): void
    {
        $stale = array_values(array_filter(FindingCategory::cases(), static fn(FindingCategory $c): bool => StaleCategories::isStale($c->value)));

        assertSame([FindingCategory::GhostMember, FindingCategory::ArityMismatch, FindingCategory::ReturnDrift], $stale);
    }
}
