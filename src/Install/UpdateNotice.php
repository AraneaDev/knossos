<?php

declare(strict_types=1);

namespace Knossos\Install;

/** The one line the session brief adds when a newer release is known. */
final class UpdateNotice
{
    /**
     * The line, or null: in a source checkout, without a cached answer, or when the cached
     * release is not newer than `$current`. Reads one small file and never uses the network.
     */
    public static function line(Installation $installation, string $current): ?string
    {
        if ($installation->isSourceCheckout() || $installation->mode() !== 'native') {
            return null;
        }
        $latest = (new UpdateCheck($installation->dataDir))->newerThan($current);

        return $latest === null ? null : sprintf('knossos %s is available (you have %s): run knossos self-update', $latest, $current);
    }
}
