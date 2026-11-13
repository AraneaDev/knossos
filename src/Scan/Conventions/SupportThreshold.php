<?php

declare(strict_types=1);

namespace Knossos\Scan\Conventions;

/**
 * The support a folder pattern needs before anything is said about it: at least
 * MIN_FILES files, and at least four in five of them (compared in integers).
 *
 * Kept here, below the query layer, so the brief's conventions and whatever
 * passes these numbers on to the store name the same values; the store itself
 * takes them as parameters and holds no policy.
 */
final class SupportThreshold
{
    /** Fewest files a population needs before any convention is stated. */
    public const MIN_FILES = 5;

    /** Smallest share of the population a pattern must cover, as a fraction (4/5); compared in integers. */
    public const SHARE_NUMERATOR = 4;

    /** The denominator of the share above. */
    public const SHARE_DENOMINATOR = 5;

    private function __construct() {}
}
