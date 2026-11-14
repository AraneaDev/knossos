<?php

declare(strict_types=1);

namespace Knossos\Install;

/**
 * Where releases come from.
 *
 * Everything that touches the network or a release directory sits behind this
 * interface, so the update logic can be tested without either.
 */
interface ReleaseSource
{
    /**
     * The newest released version, without a leading `v`.
     *
     * @throws InstallException when it cannot be determined
     */
    public function latest(): string;

    /**
     * Copy one asset of a release to a local file.
     *
     * @param string $version a version as {@see latest()} returns it
     * @param string $asset the asset's file name
     * @param string $to the local path to write
     * @throws InstallException when the asset is missing or the transfer fails
     */
    public function fetch(string $version, string $asset, string $to): void;
}
