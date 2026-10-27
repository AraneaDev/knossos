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

    /**
     * {@see self::kindIn()} checked on each row rather than sought in an index,
     * for a read that already seeks one source or target.
     *
     * The unary `+` keeps `kind` out of the index key. Without it SQLite seeks
     * (project, target, kind) once per kind, 23 by default, where one seek
     * of (project, target) reaches the few edges a node has; over 50,000
     * nodes that was 1.5 s against 0.2 s. A read of every edge of a kind
     * across the project wants the kind index, and keeps {@see self::kindIn()}.
     *
     * @param list<string> $kinds
     */
    public static function kindFilteredIn(string $column, array $kinds): string
    {
        return '+' . self::kindIn($column, $kinds);
    }
}
