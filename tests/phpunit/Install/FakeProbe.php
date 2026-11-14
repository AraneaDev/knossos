<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Install;

use Knossos\Install\ReleaseProbe;

/** A probe whose answers a test sets. */
final class FakeProbe implements ReleaseProbe
{
    public int $doctorRuns = 0;

    public function __construct(public ?string $doctorFailure = null, public bool $versionWorks = true) {}

    /** {@inheritDoc} */
    public function doctor(string $releaseDir, string $scratchDir): ?string
    {
        ++$this->doctorRuns;

        return $this->doctorFailure;
    }

    /** {@inheritDoc} */
    public function version(string $releaseDir, string $scratchDir): ?string
    {
        return $this->versionWorks ? trim((string) file_get_contents($releaseDir . '/VERSION')) : null;
    }
}
