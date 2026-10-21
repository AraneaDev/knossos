<?php

declare(strict_types=1);

namespace Knossos\Roots;

use InvalidArgumentException;
use JsonException;
use Knossos\Filesystem\AtomicFile;

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
     * Written through {@see AtomicFile}, so a write that fails partway (disk
     * full, for instance) never truncates the hand-edited file it would
     * otherwise clobber, and leaves no litter beside it.
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
        // A rewrite keeps the mode of a file the operator deliberately chmod'd;
        // a first write is owner-only, like the directory it lives in.
        if (!AtomicFile::writeJson($this->path, ['roots' => $roots], 0o600, true)) {
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
