<?php

declare(strict_types=1);

namespace Knossos\Git;

/**
 * The runner for git calls that list every tracked path (`ls-tree -r`,
 * `ls-files`).
 *
 * Such an answer grows with the repository rather than with the question:
 * `ls-tree -r` spends some 55 bytes an entry before the path, so 20,000
 * tracked files pass the 2 MB an ordinary answer is read under, and a caller
 * held to that loses its answer on exactly the repositories it matters most
 * for.
 */
final class GitListingRunner
{
    /**
     * Output ceiling, in bytes, for one listing. 64 MB is a listing of about
     * half a million files, and still a bound: a runaway child is stopped
     * long before it can exhaust the process.
     */
    public const OUTPUT_BYTES = 64_000_000;

    /** A hardened runner that reads its output under {@see self::OUTPUT_BYTES}. */
    public static function create(): GitProcessRunner
    {
        return new GitProcessRunner(self::OUTPUT_BYTES);
    }
}
