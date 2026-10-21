<?php

declare(strict_types=1);

namespace Knossos\Store;

/**
 * SQL fragments for filtering edges, written out alike at each query that reads them.
 *
 * Origin filters live in {@see EnrichedFacts}; this holds the rest.
 */
final class EdgeQueries
{
    /**
     * `<column> IN (?, ...)`, one placeholder per kind, for the caller to bind the kinds in order.
     *
     * @param list<string> $kinds
     */
    public static function kindIn(string $column, array $kinds): string
    {
        return sprintf('%s IN (%s)', $column, implode(',', array_fill(0, count($kinds), '?')));
    }
}
