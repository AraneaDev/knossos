<?php

declare(strict_types=1);

namespace Knossos\Scanner\Protocol;

use InvalidArgumentException;

/**
 * The confidence vocabulary: the three names a fact's confidence can have,
 * their ranks, and the SQL that ranks a stored confidence, so a query can
 * filter and sort on it.
 *
 * Confidence is stored by name; in SQL any name this does not know ranks as
 * `possible`.
 */
final class ConfidenceRank
{
    /** Each confidence name with its rank, lowest first, so two can be compared. */
    public const NAMES = ['possible' => 1, 'probable' => 2, 'certain' => 3];

    /** The rank of confidence `$name`, refusing a name that is none of {@see self::NAMES}. */
    public static function rank(string $name): int
    {
        return self::NAMES[$name] ?? throw new InvalidArgumentException('min_confidence must be possible, probable, or certain.');
    }

    /**
     * The names as a tool schema lists them, highest first.
     *
     * @return list<string>
     */
    public static function enum(): array
    {
        return array_reverse(array_keys(self::NAMES));
    }

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
