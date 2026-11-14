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
     * Look up the newest release and cache the answer; a failed lookup is cached as an attempt.
     *
     * @param string $current the running version
     * @return array{current: string, latest: string, update: bool}
     */
    public function check(string $current): array
    {
        $cache = new UpdateCheck($this->installation->directory());
        try {
            $latest = $this->source->latest();
        } catch (InstallException $error) {
            // A failed look counts as a look: an offline host must not retry at every session start.
            $cache->record(null);
            throw $error;
        }
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
     * the plugin step is all or nothing by itself, and it comes last.
     *
     * @throws InstallException
     */
    private function switchAndRefresh(string $version, string $from, bool $verify): void
    {
        $binDir = $this->installation->binDir();
        $launcher = $this->installation->launcher();
        $snapshot = $this->versions->snapshot();
        $launcherBefore = Launcher::read($launcher);
        $launcherTouched = false;
        try {
            $this->versions->switchTo($version, $from, $binDir);
            if ($verify && $this->probe->version($this->versions->versionDir($version), $this->scratch('post')) !== $version) {
                throw new InstallException(sprintf('Release %s did not start after the switch', $version));
            }
            $launcherTouched = true;
            Launcher::write($this->versions->versionDir($version) . '/launcher/knossos', $this->installation->directory(), $launcher);
            $pluginDir = $this->installation->pluginDir();
            if (is_dir($pluginDir)) {
                $failure = $this->probe->plugin($this->versions->versionDir($version), $pluginDir, $launcher, $this->installation->directory(), $this->scratch('plugin'));
                if ($failure !== null) {
                    throw new InstallException(sprintf("Release %s could not write its plugin:\n%s", $version, $failure));
                }
            }
        } catch (InstallException $error) {
            // The link may have moved before a later step failed: put everything back.
            $this->versions->restore($snapshot);
            if ($launcherTouched) {
                Launcher::restore($launcher, $launcherBefore);
            }
            throw new InstallException(sprintf('%s; %s is current again', rtrim($error->getMessage(), '.'), $from), 0, $error);
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
        $directory = $this->installation->directory();
        if (!is_dir($directory) && !@mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new InstallException('Could not create ' . $directory);
        }
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
