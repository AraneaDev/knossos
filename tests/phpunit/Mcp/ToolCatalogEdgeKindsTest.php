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
        $limits = [];
        foreach (ToolCatalog::definitions() as $tool) {
            self::collect($tool['inputSchema'], (string) $tool['name'], $limits);
        }

        assertSame(true, count($limits) > 0);
        foreach ($limits as $where => $limit) {
            assertSame(count(AbstractArchitectureQueryService::IMPACT_EDGE_KINDS), $limit, $where);
        }
        assertSame(true, count(array_filter(array_keys($limits), static fn(string $where): bool => str_contains($where, 'policies'))) > 0);
    }

    /**
     * Gathers the maxItems of every `edge_kinds` property, however deeply nested.
     *
     * @param array<string, mixed> $limits
     */
    private static function collect(mixed $schema, string $path, array &$limits): void
    {
        if (is_object($schema)) {
            $schema = (array) $schema;
        }
        if (!is_array($schema)) {
            return;
        }
        foreach ($schema as $key => $value) {
            if ($key === 'edge_kinds' && is_array($value)) {
                $limits[$path . '.edge_kinds'] = $value['maxItems'] ?? -1;
                continue;
            }
            self::collect($value, $path . '.' . $key, $limits);
        }
    }
}
