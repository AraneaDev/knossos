<?php

declare(strict_types=1);

namespace Knossos\Scan\Conventions;

/**
 * The support a folder pattern needs before anything is said about it: at least
 * MIN_FILES files, and at least four in five of them (compared in integers).
 * A used or injected type needs nine in ten, a companion four in five of the
 * additions, and an expected test three in five of the folder's files.
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

    /** Smallest share of the population that must use or inject a type before it is stated (9/10). */
    public const TYPE_SHARE_NUMERATOR = 9;

    /** The denominator of the type share. */
    public const TYPE_SHARE_DENOMINATOR = 10;

    /** Smallest share of the additions to a folder a companion must change with before it is named (4/5); stored from a lower bar. */
    public const COMPANION_SHARE_NUMERATOR = 4;

    /** The denominator of the companion share. */
    public const COMPANION_SHARE_DENOMINATOR = 5;

    /** Smallest share of the population whose tests follow a pattern before it names a test, or makes a new path one (3/5). */
    public const TEST_SHARE_NUMERATOR = 3;

    /** The denominator of the test share. */
    public const TEST_SHARE_DENOMINATOR = 5;

    private function __construct() {}
}
