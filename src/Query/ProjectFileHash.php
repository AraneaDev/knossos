<?php

declare(strict_types=1);

namespace Knossos\Query;

use InvalidArgumentException;

/**
 * The content hash of a project file no scan reads, taken from disk: a CI
 * script or a shell tool a note rests on. A note records it, and checks it
 * again when it is shown, so the note goes stale when the file changes.
 *
 * Only a regular file under the project's root counts, followed through any
 * link: a link that leads out of the root is outside the project.
 */
final class ProjectFileHash
{
    /** Marks a basis hash read from disk rather than taken from a scan. */
    public const PREFIX = 'disk:';

    /** The largest file hashed; a note's evidence is source, not data. */
    public const MAX_BYTES = 2_000_000;

    /**
     * The disk hash of `$relative` under `$root`.
     *
     * @throws InvalidArgumentException when the file is outside the project, or not a regular file of it
     */
    public static function of(string $root, string $relative): string
    {
        $base = realpath($root);
        $real = realpath($root . '/' . $relative);
        if ($base !== false && $real !== false && !str_starts_with($real, rtrim($base, '/') . '/')) {
            throw new InvalidArgumentException(sprintf('evidence path %s is outside the project.', $relative));
        }
        if ($base === false || $real === false || !is_file($real) || filesize($real) > self::MAX_BYTES) {
            throw new InvalidArgumentException(sprintf('evidence path %s is not a file of this project.', $relative));
        }
        $hash = hash_file('sha256', $real);
        if ($hash === false) {
            throw new InvalidArgumentException(sprintf('evidence path %s is not a file of this project.', $relative));
        }

        return self::PREFIX . $hash;
    }

    /** The disk hash of `$relative` under `$root` as it is now, or null when it no longer is one. */
    public static function current(string $root, string $relative): ?string
    {
        try {
            return self::of($root, $relative);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
