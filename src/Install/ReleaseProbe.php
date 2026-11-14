<?php

declare(strict_types=1);

namespace Knossos\Install;

/** Runs an unpacked release to see whether it works, without touching the real data directory. */
interface ReleaseProbe
{
    /**
     * Run `knossos doctor` of a release against a scratch data directory.
     *
     * @return string|null null when it passes, otherwise what it printed
     */
    public function doctor(string $releaseDir, string $scratchDir): ?string;

    /**
     * The version a release reports, or null when it does not run.
     */
    public function version(string $releaseDir, string $scratchDir): ?string;
}
