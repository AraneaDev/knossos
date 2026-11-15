<?php

declare(strict_types=1);

namespace Knossos\Install;

/**
 * Updates an installed release in place, or rolls it back.
 *
 * One run at a time (a lock file in the install directory). An update fetches
 * the release, checks it against SHA256SUMS, runs its doctor against a scratch
 * data directory, and only then switches `current`; if the switched release
 * then fails to answer, the previous state is restored. After the switch the
 * launcher and the plugin are rewritten from the release switched to, so
 * neither keeps running a version that is no longer current. Exactly one
 * previous version is kept.
 */
final class SelfUpdater
{
    private readonly VersionSwitch $versions;

    public function __construct(
        private readonly Installation $installation,
        private readonly ReleaseSource $source,
        private readonly ReleaseProbe $probe,
        private readonly string $platform,
    ) {
        $this->versions = new VersionSwitch($installation->directory());
    }

    /**
     * Look up the newest release and cache the answer; the attempt is cached before the lookup starts.
     *
     * A look counts as a look even when it never finishes: the session hook
     * runs this under its own time limit, which kills a slow lookup before it
     * could record anything, and an offline or slow host must not try again
     * at every session start. The last known answer is kept until a lookup
     * brings a new one.
     *
     * @param string $current the running version
     * @return array{current: string, latest: string, update: bool}
     */
    public function check(string $current): array
    {
        $cache = new UpdateCheck($this->installation->directory());
        $cache->record(null);
        $latest = $this->source->latest();
        $cache->record($latest);

        return ['current' => $current, 'latest' => $latest, 'update' => version_compare($latest, $current, '>')];
    }

    /**
     * Install a release and switch to it.
     *
     * @param string|null $requested a version to install, even an older one; null for the newest, and only when newer
     * @return array{status: string, from: string, to: string}
     * @throws InstallException
     */
    public function update(?string $requested): array
    {
        return $this->locked(function () use ($requested): array {
            // Before anything is fetched: an install record that does not say where the launcher is cannot be updated.
            $this->installation->launcher();
            $current = $this->versions->current() ?? throw new InstallException('There is no installed release to update: current is missing; run the installer again');
            $target = $requested === null ? $this->source->latest() : ReleaseAsset::assertVersion(ltrim($requested, 'v'));
            if ($requested === null ? version_compare($target, $current, '<=') : $target === $current) {
                return ['status' => 'current', 'from' => $current, 'to' => $current];
            }
            $this->install($target);
            $this->switchAndRefresh($target, $current, verify: true);
            $this->versions->prune([$target, $current]);
            if ($requested === null) {
                (new UpdateCheck($this->installation->directory()))->record($target);
            }

            return ['status' => 'updated', 'from' => $current, 'to' => $target];
        });
    }

    /**
     * Switch back to the version kept before the last update.
     *
     * @return array{status: string, from: string, to: string}
     * @throws InstallException
     */
    public function rollback(): array
    {
        return $this->locked(function (): array {
            $this->installation->launcher();
            $current = $this->versions->current() ?? throw new InstallException('There is no installed release: current is missing; run the installer again');
            $previous = $this->versions->previous() ?? throw new InstallException('There is no previous version to roll back to');
            $this->switchAndRefresh($previous, $current, verify: false);

            return ['status' => 'rolled back', 'from' => $current, 'to' => $previous];
        });
    }

    /**
     * Switch `current` to `$version`, then bring the launcher and the plugin up to it; undo all of it on any failure.
     *
     * In this order, each step only once the one before it worked:
     * 1. `current`, `install.json` (with the bin directory carried over) and `previous-version`;
     * 2. with `$verify`, the release must answer `version` with its own version;
     * 3. the launcher is rewritten from the release's `launcher/knossos`, in one rename;
     * 4. when the installation has a plugin directory, the release writes its own plugin
     *    there, naming the launcher, and runs no `claude` command. The directory is the
     *    one Claude Code has registered, so the registration stays valid.
     * A failure at any step puts back the link, both files and the launcher as they were;
     * the plugin step is all or nothing by itself, and it comes last. The message names
     * that one failure: a launcher write that fails leaves the old launcher in place (it
     * is a rename), so there is nothing to put back, and a launcher that cannot be put
     * back after a later failure is added to the message, never put in its place.
     *
     * The plugin keeps the graph directory its hooks already had, so a directory
     * someone set with `install-agent-plugin --data-dir` survives the refresh; only a
     * plugin without one gets the install directory.
     *
     * @throws InstallException
     */
    private function switchAndRefresh(string $version, string $from, bool $verify): void
    {
        $binDir = $this->installation->binDir();
        $launcher = $this->installation->launcher();
        $snapshot = $this->versions->snapshot();
        $launcherBefore = Launcher::read($launcher);
        $launcherWritten = false;
        $pluginDir = $this->installation->pluginDir();
        $dataDir = $this->installation->pluginDataDir() ?? $this->installation->directory();
        try {
            $this->versions->switchTo($version, $from, $binDir);
            if ($verify && $this->probe->version($this->versions->versionDir($version), $this->scratch('post')) !== $version) {
                throw new InstallException(sprintf('Release %s did not start after the switch', $version));
            }
            Launcher::write($this->versions->versionDir($version) . '/launcher/knossos', $this->installation->directory(), $launcher);
            $launcherWritten = true;
            if (is_dir($pluginDir)) {
                // The release's own CLI writes the plugin: see ProcessReleaseProbe::pluginArguments for the contract.
                $failure = $this->probe->plugin($this->versions->versionDir($version), $pluginDir, $launcher, $dataDir, $this->scratch('plugin'));
                if ($failure !== null) {
                    throw new InstallException(sprintf('Release %s could not write its plugin: %s', $version, $failure));
                }
            }
        } catch (InstallException $error) {
            // The link may have moved before a later step failed: put everything back.
            $this->versions->restore($snapshot);
            $message = sprintf('%s; %s is current again', rtrim($error->getMessage(), '.'), $from);
            if ($launcherWritten) {
                try {
                    Launcher::restore($launcher, $launcherBefore);
                } catch (InstallException) {
                    $message .= sprintf(', but the launcher %s could not be put back: run the installer again', $launcher);
                }
            }
            throw new InstallException($message, 0, $error);
        }
    }

    /**
     * Make a version complete on disk: fetch, verify, unpack, doctor. Nothing points at it yet.
     *
     * @throws InstallException
     */
    private function install(string $version): void
    {
        if ($this->versions->isComplete($version)) {
            return;
        }
        $scratch = $this->scratch('download');
        $asset = ReleaseAsset::tarball($version, $this->platform);
        $this->source->fetch($version, ReleaseAsset::CHECKSUMS, $scratch . '/' . ReleaseAsset::CHECKSUMS);
        $this->source->fetch($version, $asset, $scratch . '/' . $asset);
        $expected = ReleaseAsset::expectedChecksum((string) file_get_contents($scratch . '/' . ReleaseAsset::CHECKSUMS), $asset)
            ?? throw new InstallException(sprintf('%s has no entry for %s; this release has no build for %s', ReleaseAsset::CHECKSUMS, $asset, $this->platform));
        $actual = hash_file('sha256', $scratch . '/' . $asset);
        if ($actual !== $expected) {
            throw new InstallException(sprintf('Checksum mismatch for %s (expected %s, got %s); nothing was changed', $asset, $expected, (string) $actual));
        }
        $partial = $this->versions->unpack($scratch . '/' . $asset, $version);
        $failure = $this->probe->doctor($partial, $this->scratch('doctor'));
        if ($failure !== null) {
            $this->versions->discard($partial);
            throw new InstallException(sprintf("knossos doctor failed on the new release; the current release is untouched:\n%s", $failure));
        }
        $this->versions->adopt($partial, $version);
    }

    /**
     * A scratch directory inside the install directory, removed by {@see locked()} when the run ends.
     *
     * Under the install directory rather than /tmp so a download never fills a small tmpfs.
     */
    private function scratch(string $name): string
    {
        $directory = $this->installation->directory() . '/self-update.tmp/' . $name;
        if (!is_dir($directory)) {
            @mkdir($directory, 0o700, true);
        }

        return $directory;
    }

    /**
     * Run under an exclusive lock, refusing when another run holds it, and clean the scratch space after.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     * @throws InstallException
     */
    private function locked(callable $work): mixed
    {
        // It exists: an installation is only one when its install.json is readable there.
        $directory = $this->installation->directory();
        $lock = @fopen($directory . '/self-update.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new InstallException('Another knossos self-update is running; try again when it has finished');
        }
        try {
            return $work();
        } finally {
            self::removeTree($directory . '/self-update.tmp');
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Delete a directory tree. */
    private static function removeTree(string $path): void
    {
        foreach (is_dir($path) && !is_link($path) ? (scandir($path) ?: []) : [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                is_dir($path . '/' . $entry) && !is_link($path . '/' . $entry) ? self::removeTree($path . '/' . $entry) : @unlink($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
