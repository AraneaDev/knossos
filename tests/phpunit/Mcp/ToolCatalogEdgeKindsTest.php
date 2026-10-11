<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Mcp;

use Knossos\Mcp\ToolCatalog;
use Knossos\Query\AbstractArchitectureQueryService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

final class ToolCatalogEdgeKindsTest extends KnossosTestCase
{
    #[Group('mcp')]
    public function testEveryEdgeKindsFilterAcceptsAsManyKindsAsTheGraphHas(): void
    {
        $seen = 0;
        foreach (ToolCatalog::definitions() as $tool) {
            $properties = $tool['inputSchema']['properties'];
            $property = is_array($properties) ? ($properties['edge_kinds'] ?? null) : null;
            if ($property === null) {
                continue;
            }
            ++$seen;
            assertSame(count(AbstractArchitectureQueryService::IMPACT_EDGE_KINDS), $property['maxItems'], (string) $tool['name']);
        }
        assertSame(true, $seen > 0);
    }
}
