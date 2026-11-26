<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\Fixtures;

use Closure;
use Knossos\Filesystem\RegularFileOpener;
use Knossos\Roots\RootGuard;

/**
 * The fixture files of a project tree, found by a bounded walk (Decision 6,
 * preflight F15).
 *
 * Containment, the project's exclusions and bounded reads are Knossos's own:
 * {@see RootGuard::contains()} on each real path, the caller's exclusion
 * predicate (the scan's ignores and `.gitignore` files), and
 * {@see RegularFileOpener} for each read. What this adds is Nemesis's path
 * predicate ({@see FixturePaths}) and the caps: a file over
 * {@see self::MAX_BYTES} is skipped and counted, and the walk stops at
 * {@see self::MAX_FILES} files and says so.
 *
 * Each real file and directory is visited once. A symlink is followed only
 * when its target lies inside the root; one that leaves it, loops or is
 * broken is skipped and counted. A path the caller names is taken at its
 * word, as in Nemesis (`staleFixtures.ts:534-538`): every JSON or YAML file
 * under it is a fixture, whatever its directory.
 */
final class FixtureFiles
{
    /** The largest file read, Nemesis's per-file budget (`staleFixtures.ts:126`). */
    public const MAX_BYTES = 2_000_000;

    /** The most fixture files one walk lists. */
    public const MAX_FILES = 2000;

    /** @var list<string> */
    private array $files = [];

    /** @var array<string, true> real paths already visited */
    private array $visited = [];

    private int $skipped = 0;

    private bool $truncated = false;

    /**
     * @param Closure(string, bool): bool $excluded whether the project's exclusions leave out a project-relative path, a directory when the flag is set
     */
    private function __construct(private readonly string $root, private readonly Closure $excluded) {}

    /**
     * The fixture files under `$root`, or under the paths named, sorted;
     * how many were skipped; and whether the cap cut the list.
     *
     * @param list<string> $paths project-relative files or directories inside the root; empty walks the whole tree
     * @param Closure(string, bool): bool $excluded
     * @return array{files: list<string>, skipped: int, truncated: bool}
     */
    public static function walk(string $root, array $paths, Closure $excluded): array
    {
        $real = realpath($root);
        if ($real === false) {
            return ['files' => [], 'skipped' => 0, 'truncated' => false];
        }
        $walk = new self($real, $excluded);
        foreach ($paths === [] ? [''] : $paths as $path) {
            $walk->visit($path, $path !== '');
        }
        $files = array_values(array_unique($walk->files));
        sort($files, SORT_STRING);

        return ['files' => $files, 'skipped' => $walk->skipped, 'truncated' => $walk->truncated];
    }

    /**
     * A fixture file's bytes; null when it is no longer a regular file inside
     * the root, or is over the cap (the caller counts it as skipped).
     */
    public static function read(string $root, string $relativePath): ?string
    {
        $path = rtrim($root, '/') . '/' . $relativePath;
        $real = realpath($path);
        $realRoot = realpath($root);
        if ($real === false || $realRoot === false || !RootGuard::contains($realRoot, $real)) {
            return null;
        }
        $handle = RegularFileOpener::open($real, self::MAX_BYTES);
        if ($handle === null) {
            return null;
        }
        try {
            $bytes = stream_get_contents($handle, self::MAX_BYTES + 1);
        } finally {
            fclose($handle);
        }

        return is_string($bytes) && strlen($bytes) <= self::MAX_BYTES ? $bytes : null;
    }

    /**
     * Visit one project-relative path: a directory is walked, a file listed
     * when it is a fixture. `$named` takes every JSON or YAML file below at
     * its word.
     */
    private function visit(string $relativePath, bool $named): void
    {
        if ($this->truncated) {
            return;
        }
        $path = $this->root . ($relativePath === '' ? '' : '/' . $relativePath);
        $real = realpath($path);
        if ($real === false || !RootGuard::contains($this->root, $real)) {
            // A broken link, a loop, or a link out of the root.
            ++$this->skipped;

            return;
        }
        if (isset($this->visited[$real])) {
            return;
        }
        if (is_dir($real)) {
            if ($relativePath !== '' && ($this->excluded)($relativePath, true)) {
                return;
            }
            $this->visited[$real] = true;
            $entries = @scandir($real);
            foreach ($entries === false ? [] : $entries as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->visit($relativePath === '' ? $entry : $relativePath . '/' . $entry, $named);
                }
            }

            return;
        }
        if (!is_file($real) || !FixturePaths::hasFixtureExtension($relativePath) || ($this->excluded)($relativePath, false) || (!$named && !FixturePaths::isFixturePath($relativePath))) {
            return;
        }
        $this->visited[$real] = true;
        if (filesize($real) > self::MAX_BYTES) {
            ++$this->skipped;

            return;
        }
        if (count($this->files) >= self::MAX_FILES) {
            $this->truncated = true;

            return;
        }
        $this->files[] = $relativePath;
    }
}
