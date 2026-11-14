<?php

declare(strict_types=1);

namespace Knossos\Install;

/**
 * Updates an installed release in place, or rolls it back.
 *
 * One run at a time (a lock file in the data directory). An update fetches
 * the release, checks it against SHA256SUMS, runs its doctor against a scratch
 * data directory, and only then switches `current`; if the switched release
 * then fails to answer, the previous state is restored. Exactly one previous
 * version is kept.
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
        $this->versions = new VersionSwitch($installation->dataDir);
    }

    /**
     * Look up the newest release and cache the answer; a failed lookup is cached as an attempt.
     *
     * @param string $current the running version
     * @return array{current: string, latest: string, update: bool}
     */
    public function check(string $current): array
    {
        $cache = new UpdateCheck($this->installation->dataDir);
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
            $snapshot = $this->versions->snapshot();
            try {
                $this->versions->switchTo($target, $current);
            } catch (InstallException $error) {
                // The link may have moved before a later write failed: put everything back.
                $this->versions->restore($snapshot);
                throw $error;
            }
            if ($this->probe->version($this->versions->versionDir($target), $this->scratch('post')) !== $target) {
                $this->versions->restore($snapshot);
                throw new InstallException(sprintf('Release %s did not start after the switch; %s is current again', $target, $current));
            }
            $this->versions->prune([$target, $current]);
            if ($requested === null) {
                (new UpdateCheck($this->installation->dataDir))->record($target);
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
            $this->versions->switchTo($previous, $current);

            return ['status' => 'rolled back', 'from' => $current, 'to' => $previous];
        });
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
     * A scratch directory inside the data directory, removed by {@see locked()} when the run ends.
     *
     * Under the data directory rather than /tmp so a download never fills a small tmpfs.
     */
    private function scratch(string $name): string
    {
        $directory = $this->installation->dataDir . '/self-update.tmp/' . $name;
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
        $directory = $this->installation->dataDir;
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
