<?php

declare(strict_types=1);

namespace Knossos\Install;

/** The one line the session brief adds when a newer release is known. */
final class UpdateNotice
{
    /**
     * The line, or null: with KNOSSOS_NO_UPDATE_CHECK=1, in a source checkout, without a cached answer, or when the cached
     * release is not newer than `$current`. Reads one small file and never uses the network. A container install reads
     * the same file through the install directory the launcher mounts, and is updated with the same command; a record whose mode does not
     * fit where the code sits (`container` beside `versions/`, say) gives no line.
     */
    public static function line(Installation $installation, string $current): ?string
    {
        if (getenv('KNOSSOS_NO_UPDATE_CHECK') === '1' || $installation->isSourceCheckout() || $installation->mode() !== ($installation->layoutDir === null ? 'container' : 'native')) {
            return null;
        }
        $latest = (new UpdateCheck($installation->directory()))->newerThan($current);

        return $latest === null ? null : sprintf('knossos %s is available (you have %s): run knossos self-update', $latest, $current);
    }
}
