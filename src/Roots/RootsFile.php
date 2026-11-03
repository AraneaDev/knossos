<?php

declare(strict_types=1);

namespace Knossos\Roots;

use InvalidArgumentException;
use Knossos\Filesystem\AtomicFile;
use Knossos\Filesystem\JsonFile;

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
        return JsonFile::roots($this->path, true);
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
        JsonFile::writeRoots($this->path, $roots);
    }

    /**
     * Add a root unless it is already listed, under an exclusive lock.
     *
     * Starts now add roots unprompted, so two at once would each read the same
     * list and the later write would drop the earlier one's entry. The lock is
     * a file beside the roots file, held across the read and the write, so the
     * second start reads what the first wrote.
     *
     * @return bool whether the root was added
     * @throws InvalidArgumentException when the file cannot be understood or written, or the lock cannot be taken
     */
    public function add(string $root): bool
    {
        JsonFile::ensureDirectoryOf($this->path);
        $lock = @fopen($this->path . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new InvalidArgumentException(sprintf('Unable to lock %s.', $this->path));
        }
        try {
            $roots = $this->read();
            $root = AllowedRoots::normaliseRoot($root);
            if (in_array($root, array_map(AllowedRoots::normaliseRoot(...), $roots), true)) {
                return false;
            }
            $roots[] = $root;
            $this->write($roots);

            return true;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
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
