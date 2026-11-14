<?php

declare(strict_types=1);

namespace Knossos\Install;

/**
 * Runs an unpacked release's own `bin/knossos`, without touching the real data directory.
 *
 * Checks whether a release works, and has it write the files that must come
 * from the release itself rather than from the code that is running.
 */
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

    /**
     * Have a release materialise its own plugin, running no `claude` command.
     *
     * The release's `install-agent-plugin --execute --no-register` writes
     * `$pluginDir` from its own files and with its own version, baking
     * `$launcher` and `$dataDir` into the hooks.
     *
     * @return string|null null when it worked, otherwise what it printed
     */
    public function plugin(string $releaseDir, string $pluginDir, string $launcher, string $dataDir, string $scratchDir): ?string;
}
