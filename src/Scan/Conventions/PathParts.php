<?php

declare(strict_types=1);

namespace Knossos\Scan\Conventions;

/**
 * The parts of a project-relative path that the folder conventions group and name by.
 */
final class PathParts
{
    /** The directory of a path, empty for a file at the root. */
    public static function directory(string $path): string
    {
        $directory = dirname($path);

        return $directory === '.' ? '' : $directory;
    }

    /** A file's name up to its first dot, so `a.spec.ts` and `a.ts` both name `a`. */
    public static function stem(string $path): string
    {
        $base = basename($path);
        $dot = strpos($base, '.');

        return $dot === false || $dot === 0 ? $base : substr($base, 0, $dot);
    }

    /** The lower-case part of the file name after its last dot, empty without one. */
    public static function extension(string $path): string
    {
        $base = basename($path);
        $dot = strrpos($base, '.');

        return $dot === false ? '' : strtolower(substr($base, $dot + 1));
    }
}
