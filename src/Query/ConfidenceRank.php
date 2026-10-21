<?php

declare(strict_types=1);

namespace Knossos\Query;

/**
 * The SQL that ranks a stored confidence, so a query can filter and sort on it.
 *
 * Confidence is stored by name; the ranks are the ones
 * {@see AbstractArchitectureQueryService::CONFIDENCE_RANK} holds, and any
 * name it does not know ranks as `possible`.
 */
final class ConfidenceRank
{
    /** The rank of the confidence in `$column`, as a SQL expression. */
    public static function of(string $column): string
    {
        return "CASE {$column} WHEN 'certain' THEN 3 WHEN 'probable' THEN 2 ELSE 1 END";
    }

    /** A condition that the confidence in `$column` reaches the rank bound to its one placeholder. */
    public static function atLeast(string $column): string
    {
        return self::of($column) . ' >= CAST(? AS INTEGER)';
    }
}
