<?php

declare(strict_types=1);

namespace Knossos\Reconciliation;

/**
 * Durations in milliseconds, from `hrtime(true)` readings, to the microsecond.
 *
 * The conversion is a function of its own so it can be tested on fixed
 * nanosecond spans: read off a live clock, the divisor and the rounding
 * precision would only ever be seen through whatever a scan happened to take.
 */
final class Milliseconds
{
    /** Milliseconds since a `hrtime(true)` mark. */
    public static function since(int|float $mark): float
    {
        return self::fromNanoseconds(hrtime(true) - $mark);
    }

    /** A span of nanoseconds in milliseconds, rounded to three decimals. */
    public static function fromNanoseconds(int|float $nanoseconds): float
    {
        return round($nanoseconds / 1_000_000, 3);
    }
}
