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

    /** The graph file inside a data directory. */
    public static function databaseIn(string $directory): string
    {
        return rtrim($directory, '/') . '/knossos.sqlite';
    }

    /**
     * The graph in the conventional data directory under `$directory`, a
     * project or home directory: `<directory>/.knossos/knossos.sqlite`,
     * without doubling a trailing slash at the filesystem root.
     */
    public static function databaseUnder(string $directory): string
    {
        return self::databaseIn(rtrim($directory, '/') . '/.knossos');
    }

    /** The named directory as an absolute path, or null when the variable is unset or empty. */
    public static function resolve(): ?string
    {
        $directory = self::named();
        if ($directory === null || str_starts_with($directory, '/')) {
            return $directory;
        }
        $cwd = getcwd();

        return $cwd === false ? $directory : rtrim($cwd, '/') . '/' . $directory;
    }

    /**
     * The variable's value exactly as set, or null when it is unset or empty.
     *
     * For a caller that must refuse a relative value rather than anchor it.
     */
    public static function named(): ?string
    {
        $directory = getenv(self::VARIABLE);

        return is_string($directory) && $directory !== '' ? $directory : null;
    }
}
