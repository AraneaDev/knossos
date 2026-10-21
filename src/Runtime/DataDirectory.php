<?php

declare(strict_types=1);

namespace Knossos\Runtime;

/**
 * The data directory `KNOSSOS_DATA_DIR` names, read the same way by every caller.
 *
 * Unset and empty both mean the variable names no directory, so the caller
 * falls back to its own default. A relative value is anchored to the working
 * directory once, here: the documentation asks for an absolute path, and a
 * relative one otherwise means a different graph to whatever resolves it from
 * another directory.
 */
final class DataDirectory
{
    /** The environment variable naming the directory. */
    public const VARIABLE = 'KNOSSOS_DATA_DIR';

    /** The named directory as an absolute path, or null when the variable is unset or empty. */
    public static function resolve(): ?string
    {
        $directory = getenv(self::VARIABLE);
        if (!is_string($directory) || $directory === '') {
            return null;
        }
        if (str_starts_with($directory, '/')) {
            return $directory;
        }
        $cwd = getcwd();

        return $cwd === false ? $directory : rtrim($cwd, '/') . '/' . $directory;
    }
}
