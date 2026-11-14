<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Install;

use Knossos\Install\InstallException;
use Knossos\Install\ReleaseSource;

/** A source that must never be asked: proves a code path makes no release lookup. */
final class ForbiddenSource implements ReleaseSource
{
    /** {@inheritDoc} */
    public function latest(): string
    {
        throw new InstallException('the network was used');
    }

    /** {@inheritDoc} */
    public function fetch(string $version, string $asset, string $to): void
    {
        throw new InstallException('the network was used');
    }
}
