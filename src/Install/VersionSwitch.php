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
    /**
     * Test seam, never set in production: called with the entry name between the
     * `lstat` and the `chdir` of {@see emptyHere()}, to stand in for a concurrent swap.
     *
     * @var (\Closure(string): void)|null
     */
    public static ?\Closure $betweenCheckAndEnter = null;

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
            throw new InstallException(sprintf('The download is not a readable archive: %s', self::reason($error)), 0, $error);
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
            throw new InstallException(sprintf('Could not unpack the archive: %s', self::reason($error)), 0, $error);
        }
        try {
            self::assertInside((string) realpath($partial));
        } catch (InstallException $error) {
            self::remove($partial);
            throw $error;
        }
        if (!is_executable($partial . '/bin/knossos') || trim((string) @file_get_contents($partial . '/VERSION')) !== $version) {
            self::remove($partial);
            throw new InstallException(sprintf('The archive does not hold a complete release of %s', $version));
        }

        return $partial;
    }

    /**
     * Walk an unpacked release on disk, without following links, and refuse what could reach outside it.
     *
     * Fails closed: a directory that cannot be read and searched, a listing
     * that fails and a path near PATH_MAX are refusals, never an empty
     * listing. Only regular files, directories and symbolic links are allowed.
     * Every link is resolved physically, one component at a time, and no step
     * may leave the release, so a chain of links cannot add up to an escape.
     *
     * @throws InstallException naming the offending member
     */
    private static function assertInside(string $root, string $directory = ''): void
    {
        $here = $root . ($directory === '' ? '' : '/' . $directory);
        if (strlen($here) > 3800) {
            throw new InstallException(sprintf('Path too long in the archive: %s', self::printable($directory)));
        }
        if (!is_readable($here) || !is_executable($here)) {
            throw new InstallException(sprintf('Unreadable directory in the archive: %s', self::printable($directory)));
        }
        $entries = scandir($here);
        if ($entries === false) {
            throw new InstallException(sprintf('Unreadable directory in the archive: %s', self::printable($directory)));
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $member = ($directory === '' ? '' : $directory . '/') . $entry;
            $path = $root . '/' . $member;
            if (is_link($path)) {
                $target = (string) readlink($path);
                if (!self::resolvesInside($root, $here, $target)) {
                    throw new InstallException(sprintf('Unsafe link in the archive: %s -> %s', self::printable($member), self::printable($target)));
                }
            } elseif (is_dir($path)) {
                self::assertInside($root, $member);
            } elseif (!is_file($path)) {
                throw new InstallException(sprintf('Unsupported entry in the archive (not a file, directory or link): %s', self::printable($member)));
            }
        }
    }

    /**
     * Whether a link in `$directory` resolves, step by step, inside `$root`.
     *
     * Each component that exists is resolved with realpath, so a `..` after
     * another link is taken from where that link really leads. From the first
     * component that does not exist, the rest is followed lexically from the
     * last real directory, and a `..` that would climb above the release is a
     * refusal: the missing directory may appear later. A dangling link is
     * expanded in place, and a loop of links, which never stops expanding, is
     * a refusal outright.
     */
    private static function resolvesInside(string $root, string $directory, string $target): bool
    {
        $inside = static fn(string $path): bool => $path === $root || str_starts_with($path, $root . '/');
        $current = realpath($directory);
        if ($target === '' || str_starts_with($target, '/') || $current === false || !$inside($current)) {
            return false;
        }
        $queue = explode('/', $target);
        $missing = 0;
        $hops = 0;
        while ($queue !== []) {
            $segment = array_shift($queue);
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($missing > 0) {
                    --$missing;
                } elseif (!$inside($current = dirname($current))) {
                    return false;
                }
            } elseif ($missing > 0) {
                ++$missing;
            } else {
                $next = $current . '/' . $segment;
                if (@lstat($next) === false) {
                    $missing = 1;
                } elseif (($resolved = realpath($next)) !== false) {
                    if (!$inside($resolved)) {
                        return false;
                    }
                    $current = $resolved;
                } else {
                    // A link that leads nowhere, or round in a circle: read it, and let the hop count end a loop.
                    $link = is_link($next) ? readlink($next) : false;
                    if ($link === false || $link === '' || str_starts_with($link, '/') || ++$hops > 40) {
                        return false;
                    }
                    array_unshift($queue, ...explode('/', $link));
                }
            }
        }

        return true;
    }

    /** A name fit for a message and for JSON: bytes that are not valid UTF-8 are replaced. */
    private static function printable(string $name): string
    {
        return mb_scrub($name, 'UTF-8');
    }

    /** The cause of a failed tar run, without the Git wording of the runner that carried it. */
    private static function reason(Throwable $error): string
    {
        return preg_replace('/^Git [a-z ]+ unavailable: (?:tar: )?/', 'tar: ', $error->getMessage()) ?? $error->getMessage();
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
            $bytes = is_file($this->dataDir . '/' . $name) ? @file_get_contents($this->dataDir . '/' . $name) : false;
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
     * Delete every version except the ones named, and the partial unpacks of runs that are gone.
     *
     * @param list<string> $keep
     */
    public function prune(array $keep): void
    {
        foreach (glob($this->dataDir . '/versions/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $name = basename($directory);
            if (preg_match('/\.partial\.(\d+)$/', $name, $match) === 1) {
                // Another run's unpack, unless that run is gone: a crash leaves a whole release behind.
                if (!self::alive((int) $match[1])) {
                    self::remove($directory);
                }
            } elseif (!in_array($name, $keep, true)) {
                self::remove($directory);
            }
        }
    }

    /** Whether a process is running; true when that cannot be told. */
    private static function alive(int $pid): bool
    {
        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0) || posix_get_last_error() === 1;
        }

        return !is_dir('/proc') || is_dir('/proc/' . $pid);
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
        self::removeByPath($path);
        if (file_exists($path) || is_link($path)) {
            // Left behind: a tree deeper than PATH_MAX cannot be reached by its full path.
            self::removeFromInside($path);
        }
    }

    /** Delete a tree by full paths, which works as long as none of them is longer than PATH_MAX. */
    private static function removeByPath(string $path): void
    {
        if (is_link($path) || !is_dir($path)) {
            // Files, links and the odd entry a hostile archive carries, a fifo or a device node.
            @unlink($path);

            return;
        }
        // A directory the archive made unreadable still has to go: open it up on the way down.
        @chmod($path, 0o700);
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeByPath($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }

    /**
     * Delete a directory tree of any depth by changing into it, level by level.
     *
     * Names relative to the current directory never grow past one component,
     * so no call sees a path longer than PATH_MAX. Links are removed, never
     * entered, and the working directory is put back whatever happens.
     */
    private static function removeFromInside(string $path): void
    {
        $origin = getcwd();
        if ($origin === false || is_link($path) || !is_dir($path)) {
            return;
        }
        $absolute = str_starts_with($path, '/') ? $path : $origin . '/' . $path;
        try {
            if (@chdir($absolute)) {
                self::emptyHere();
            }
        } finally {
            chdir($origin);
        }
        @rmdir($absolute);
    }

    /**
     * Delete everything in the current directory, descending by relative names.
     *
     * An entry is entered only if it is still the directory that was looked at:
     * the device and inode of the directory that `chdir` lands in must match
     * the `lstat` taken before it. If something swapped the entry for a link
     * in between, the descent is refused and nothing there is deleted.
     *
     * @throws InstallException when an entry was replaced between the check and the descent
     */
    private static function emptyHere(): void
    {
        foreach (scandir('.') ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (is_link($entry) || !is_dir($entry)) {
                @unlink($entry);

                continue;
            }
            @chmod($entry, 0o700);
            $seen = @lstat($entry);
            if (self::$betweenCheckAndEnter !== null) {
                (self::$betweenCheckAndEnter)($entry);
            }
            if ($seen !== false && @chdir($entry)) {
                if (!self::isSameDirectory($seen)) {
                    // Not going back by name: `..` of a swapped-in directory is somewhere else.
                    // The caller's `finally` puts the working directory back.
                    throw new InstallException('A directory changed while it was being removed; nothing more was deleted');
                }
                self::emptyHere();
                chdir('..');
            }
            @rmdir($entry);
        }
    }

    /** Whether the current directory is the one an earlier `lstat` described. @param array<int|string, int> $seen */
    private static function isSameDirectory(array $seen): bool
    {
        $now = @stat('.');

        return $now !== false && $now['dev'] === $seen['dev'] && $now['ino'] === $seen['ino'];
    }
}
