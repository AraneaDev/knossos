<?php

declare(strict_types=1);

namespace Knossos\Install;

/**
 * The Python worker's bytecode, compiled once where Knossos is installed
 * (G4-16, the user's decision U2 of 2026-11-27).
 *
 * The core starts the worker with `-B`, so it writes no bytecode, and a
 * release ships none: without this every scan would compile the worker's
 * package again, about 70ms a start. The host's own `python3` compiles it at
 * install, so the bytecode carries that interpreter's cache tag, and never at
 * scan time. Each file is a checked-hash `.pyc`: the interpreter hashes the
 * source on every import and runs the bytecode only when the hash matches, so
 * a source edited after install, or a stale `.pyc`, falls back to the source
 * and never runs.
 *
 * `install.sh`, `tools/install` and the `Dockerfile` run the same command
 * over the same directory; `PythonWorkerBytecodeTest` holds them to it.
 */
final class PythonBytecode
{
    /** Where the worker's sources sit below an installation root. */
    public const DIRECTORY = 'workers/python/bin';

    /** The command, to which the directory is appended. */
    public const COMMAND = ['python3', '-I', '-m', 'compileall', '-q', '--invalidation-mode', 'checked-hash'];

    private function __construct() {}

    /**
     * Compile the worker below `$root`; whether it compiled. A missing
     * directory or interpreter compiles nothing, and the worker then runs
     * from its source as before, so a failure is never fatal to an install.
     */
    public static function compile(string $root): bool
    {
        $directory = rtrim($root, '/') . '/' . self::DIRECTORY;
        if (!is_dir($directory)) {
            return false;
        }
        $process = @proc_open([...self::COMMAND, $directory], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);

        return is_resource($process) && proc_close($process) === 0;
    }
}
