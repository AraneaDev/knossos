<?php

declare(strict_types=1);

namespace Knossos\Install;

use Knossos\Git\GitProcessRunner;
use Throwable;

/**
 * The versions on disk under a data directory, and which one `current` names.
 *
 * Layout, shared with install.sh: `versions/<v>/` holds an unpacked release,
 * `current` is a relative symlink to one of them, `install.json` records the
 * mode and version. `previous-version` names the one version kept besides the
 * current one, for `--rollback`. A version is only ever renamed into place
 * once it is complete, and `current` is only ever replaced by one atomic
 * rename, so it never names an incomplete or missing version.
 */
final class VersionSwitch
{
    public function __construct(private readonly string $dataDir) {}

    /** Where a version is unpacked. */
    public function versionDir(string $version): string
    {
        return $this->dataDir . '/versions/' . $version;
    }

    /** Whether a version is unpacked completely: a runnable `bin/knossos` and a matching `VERSION`. */
    public function isComplete(string $version): bool
    {
        $directory = $this->versionDir($version);

        return is_executable($directory . '/bin/knossos')
            && trim((string) @file_get_contents($directory . '/VERSION')) === $version;
    }

    /** The version `current` points at, or null when there is no such link or it points elsewhere. */
    public function current(): ?string
    {
        $target = @readlink($this->dataDir . '/current');

        return is_string($target) && preg_match('#^(?:\./)?versions/([^/]+)/?$#', $target, $match) === 1 ? $match[1] : null;
    }

    /** The version kept for rollback, when it is still there and complete. */
    public function previous(): ?string
    {
        $version = trim((string) @file_get_contents($this->dataDir . '/previous-version'));

        return $version !== '' && preg_match('/^[0-9A-Za-z.-]+$/', $version) === 1 && $this->isComplete($version) ? $version : null;
    }

    /**
     * Unpack a tarball into a directory of its own beside the versions, and return it.
     *
     * Every member must sit under `knossos-<version>/`, with no absolute path and
     * no `..`. The directory is not a version yet: {@see adopt()} makes it one.
     *
     * @throws InstallException
     */
    public function unpack(string $archive, string $version): string
    {
        $runner = new GitProcessRunner(maxOutputBytes: 33_554_432, hardenGitConfig: false);
        try {
            $listing = $runner->run(['tar', '-tzf', $archive], 120_000, 'archive listing');
        } catch (Throwable $error) {
            throw new InstallException(sprintf('The download is not a readable archive: %s', $error->getMessage()), 0, $error);
        }
        foreach (explode("\n", trim($listing)) as $entry) {
            $inside = $entry === 'knossos-' . $version || str_starts_with($entry, 'knossos-' . $version . '/');
            if (!$inside || str_starts_with($entry, '/') || preg_match('#(^|/)\.\.(/|$)#', $entry) === 1) {
                throw new InstallException(sprintf('Unsafe or unexpected entry in the archive: %s', $entry));
            }
        }
        if (!is_dir($this->dataDir . '/versions') && !@mkdir($this->dataDir . '/versions', 0o700, true) && !is_dir($this->dataDir . '/versions')) {
            throw new InstallException('Could not create ' . $this->dataDir . '/versions');
        }
        $partial = $this->versionDir($version) . '.partial.' . getmypid();
        self::remove($partial);
        if (!@mkdir($partial, 0o700)) {
            throw new InstallException('Could not create ' . $partial);
        }
        try {
            $runner->run(['tar', '-xzf', $archive, '-C', $partial, '--strip-components=1'], 300_000, 'archive unpack');
        } catch (Throwable $error) {
            self::remove($partial);
            throw new InstallException(sprintf('Could not unpack the archive: %s', $error->getMessage()), 0, $error);
        }
        if (!is_executable($partial . '/bin/knossos') || trim((string) @file_get_contents($partial . '/VERSION')) !== $version) {
            self::remove($partial);
            throw new InstallException(sprintf('The archive does not hold a complete release of %s', $version));
        }

        return $partial;
    }

    /**
     * Rename an unpacked directory into place as a version.
     *
     * An existing incomplete directory is replaced; an existing complete one
     * (another run won the race) is kept and the new one dropped.
     */
    public function adopt(string $partial, string $version): void
    {
        $target = $this->versionDir($version);
        if (file_exists($target) && !$this->isComplete($version)) {
            self::remove($target);
        }
        if (file_exists($target)) {
            self::remove($partial);

            return;
        }
        if (!@rename($partial, $target)) {
            self::remove($partial);
            throw new InstallException('Could not move the unpacked release to ' . $target);
        }
    }

    /** Delete an unpacked directory that will not become a version. */
    public function discard(string $partial): void
    {
        self::remove($partial);
    }

    /**
     * Point `current` at a version, and record it in `install.json`.
     *
     * `$previous` is the version to keep for rollback, or null for none. The
     * link is built under a temporary name and renamed over the old one, so a
     * reader sees the old version or the new one, never neither.
     *
     * @throws InstallException
     */
    public function switchTo(string $version, ?string $previous): void
    {
        if (!$this->isComplete($version)) {
            throw new InstallException(sprintf('Version %s is not completely installed; nothing was switched', $version));
        }
        $this->link('versions/' . $version);
        $this->write('previous-version', $previous === null ? null : $previous . "\n");
        $this->write('install.json', sprintf(
            "{\n  \"mode\": \"native\",\n  \"version\": \"%s\",\n  \"image\": null,\n  \"installed_at\": \"%s\"\n}\n",
            $version,
            gmdate('Y-m-d\TH:i:s\Z'),
        ));
    }

    /**
     * What `current`, `install.json` and `previous-version` hold now, for {@see restore()}.
     *
     * @return array{link: string|null, files: array<string, string|null>}
     */
    public function snapshot(): array
    {
        $link = @readlink($this->dataDir . '/current');
        $files = [];
        foreach (['install.json', 'previous-version'] as $name) {
            $bytes = @file_get_contents($this->dataDir . '/' . $name);
            $files[$name] = $bytes === false ? null : $bytes;
        }

        return ['link' => is_string($link) ? $link : null, 'files' => $files];
    }

    /**
     * Put back what {@see snapshot()} took.
     *
     * @param array{link: string|null, files: array<string, string|null>} $snapshot
     */
    public function restore(array $snapshot): void
    {
        if ($snapshot['link'] === null) {
            @unlink($this->dataDir . '/current');
        } else {
            $this->link($snapshot['link']);
        }
        foreach ($snapshot['files'] as $name => $bytes) {
            $this->write($name, $bytes);
        }
    }

    /**
     * Delete every version except the ones named, partial unpacks of other runs excepted.
     *
     * @param list<string> $keep
     */
    public function prune(array $keep): void
    {
        foreach (glob($this->dataDir . '/versions/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $name = basename($directory);
            if (!in_array($name, $keep, true) && !str_contains($name, '.partial.')) {
                self::remove($directory);
            }
        }
    }

    /** Replace `current` with a symlink to `$target` in one rename. */
    private function link(string $target): void
    {
        $temporary = $this->dataDir . '/current.tmp.' . getmypid();
        @unlink($temporary);
        if (!@symlink($target, $temporary) || !@rename($temporary, $this->dataDir . '/current')) {
            @unlink($temporary);
            throw new InstallException('Could not switch ' . $this->dataDir . '/current');
        }
    }

    /** Write a file in the data directory atomically, or remove it for null. */
    private function write(string $name, ?string $bytes): void
    {
        $path = $this->dataDir . '/' . $name;
        if ($bytes === null) {
            @unlink($path);

            return;
        }
        $temporary = $path . '.tmp.' . getmypid();
        if (@file_put_contents($temporary, $bytes) === false || !@rename($temporary, $path)) {
            @unlink($temporary);
            throw new InstallException('Could not write ' . $path);
        }
    }

    /** Delete a file, a link or a directory tree, without following links. */
    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        foreach (is_dir($path) ? (scandir($path) ?: []) : [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::remove($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
