<?php

declare(strict_types=1);

namespace Knossos\Scan;

/**
 * Whether a scan's shutdown function should still speak: armed when the scan
 * starts, disarmed once the scan has ended some way that explained itself.
 * A property rather than a captured variable, so the change is plain to read.
 */
final class FatalWatch
{
    private bool $armed = true;

    /** Whether the scan is still running, as far as the shutdown function can tell. */
    public function armed(): bool
    {
        return $this->armed;
    }

    /** The scan ended by itself; a later fatal is not its fatal. */
    public function disarm(): void
    {
        $this->armed = false;
    }
}
