<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Support;

/**
 * The limits a test sends with a scan request, as the core always sends some:
 * the defaults a project without its own limits is scanned under.
 */
final class ScanLimits
{
    /** `max_files` and `max_file_bytes` for a test's scan request. */
    public const DEFAULT = ['max_files' => 100_000, 'max_file_bytes' => 2_000_000];

    private function __construct() {}
}
