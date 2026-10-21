<?php

declare(strict_types=1);

namespace Knossos\Roots;

use InvalidArgumentException;
use JsonException;

/**
 * The roots.json allow-list as a file: read tolerantly, written atomically.
 *
 * Shared by `allow-root` and `move-project`, the two commands that change
 * which directory trees a server may read. Only the CLI writes it; see
 * {@see AllowedRoots} for how a server reads it.
 */
final readonly class RootsFile
{
    public function __construct(public string $path) {}

    /**
     * The roots already on disk, in file order.
     *
     * Tolerates both `{"roots": [...]}` and a bare `[...]`, the same two shapes
     * {@see AllowedRoots::parse()} accepts when a running server reads this
     * file. Rejecting the bare form here would make this command call a file
     * "corrupt" that the server itself reads and honours just fine, which is
     * exactly the hand-editing dead end the command exists to remove.
     *
     * Invalid JSON is a different failure and still throws rather than being
     * treated as empty: a reader that must never grant more than it should is
     * right to fall back to nothing, but a writer must never silently replace
     * content it could not understand with just the one root this call knows
     * about.
     *
     * @return list<string>
     */
    public function read(): array
    {
        if (!is_file($this->path)) {
            return [];
        }
        $contents = file_get_contents($this->path);
        if ($contents === false) {
            throw new InvalidArgumentException(sprintf('Unable to read %s.', $this->path));
        }
        try {
            $decoded = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidArgumentException(
                sprintf('%s is not valid JSON (%s). Fix or remove it before retrying.', $this->path, $error->getMessage()),
                previous: $error,
            );
        }
        $decodedRoots = is_array($decoded) ? ($decoded['roots'] ?? $decoded) : null;
        if (!is_array($decodedRoots)) {
            throw new InvalidArgumentException(sprintf('%s does not have the expected {"roots": [...]} shape.', $this->path));
        }
        $roots = [];
        foreach ($decodedRoots as $root) {
            if (is_string($root)) {
                $roots[] = $root;
            }
        }
        return $roots;
    }

    /**
     * Write the roots file, creating its parent directory when this is the
     * first root ever granted for this database.
     *
     * Always writes the canonical `{"roots": [...]}` shape, so a bare-array
     * file that read() tolerated on the way in is normalised the first
     * time a root is added through this command.
     *
     * Written to a temporary file beside the target and renamed into place.
     * rename() is atomic only within one filesystem, so the temporary file
     * must sit in the same directory; a write that fails partway (disk full,
     * for instance) then lands on the temporary name and never truncates the
     * hand-edited file it would otherwise clobber. Every failure path here
     * removes that temporary file before throwing, so a failed run leaves no
     * litter beside the real one.
     *
     * @param list<string> $roots
     */
    public function write(array $roots): void
    {
        $directory = dirname($this->path);
        // Owner-only, as RuntimeFactory creates it: the graph that will live here is the project's structure.
        if (!is_dir($directory) && !@mkdir($directory, 0o700, true)) {
            throw new InvalidArgumentException(sprintf('Unable to create %s.', $directory));
        }
        // Captured before the temporary file is even written: rename() carries
        // over the TEMPORARY file's mode, not the target's, so a file the
        // operator deliberately chmod'd (0600, say) would otherwise silently
        // widen to the umask default the moment it is replaced. A target that
        // does not exist yet gets no mode forced onto it; the umask default is
        // the ordinary behaviour for a file created for the first time. A
        // target that exists but whose fileperms() call itself fails (a stat
        // race, say) is treated the same way, with no mode forced: an unknown
        // mode is not a reason to invent one, and `false & 0o777` would
        // otherwise coerce to int 0, silently locking the file to mode 0000.
        $existingMode = null;
        if (is_file($this->path)) {
            $stattedMode = fileperms($this->path);
            if ($stattedMode !== false) {
                $existingMode = $stattedMode & 0o777;
            }
        }

        $encoded = json_encode(['roots' => $roots], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $temporary = $directory . '/.roots.json.' . bin2hex(random_bytes(8)) . '.tmp';
        if (file_put_contents($temporary, $encoded . PHP_EOL) === false) {
            @unlink($temporary);
            throw new InvalidArgumentException(sprintf('Unable to write %s.', $this->path));
        }
        // Set on the temporary file BEFORE the rename, not after, so the file
        // is never in place, even briefly, with the wrong permissions.
        if ($existingMode !== null && !@chmod($temporary, $existingMode)) {
            @unlink($temporary);
            throw new InvalidArgumentException(sprintf('Unable to write %s.', $this->path));
        }
        if (!@rename($temporary, $this->path)) {
            @unlink($temporary);
            throw new InvalidArgumentException(sprintf('Unable to write %s.', $this->path));
        }
    }

    /**
     * Swap one granted root for another in a single write: what a moved
     * checkout needs, since the old path no longer exists and the new one
     * must be readable. Entries are compared normalised and kept verbatim.
     *
     * @return array{added: bool, removed: bool}
     */
    public function replace(?string $remove, string $add): array
    {
        $roots = $this->read();
        $add = AllowedRoots::normaliseRoot($add);
        $kept = $remove === null
            ? $roots
            : array_values(array_filter($roots, static fn(string $root): bool => AllowedRoots::normaliseRoot($root) !== AllowedRoots::normaliseRoot($remove)));
        $removed = count($kept) !== count($roots);
        $added = !in_array($add, array_map(AllowedRoots::normaliseRoot(...), $kept), true);
        if ($added) {
            $kept[] = $add;
        }
        if ($added || $removed) {
            $this->write($kept);
        }

        return ['added' => $added, 'removed' => $removed];
    }
}
