<?php

declare(strict_types=1);

namespace Knossos\Configuration;

/**
 * One command a project runs before a commit, with the paths it covers.
 *
 * Kept as a value so a creation brief can quote the gates that apply to a new
 * file without re-reading knossos.json or knowing how the loader validated it.
 */
final readonly class ProjectGate
{
    /**
     * @param string $name short identifier, unique within a project
     * @param string $command the command line to run, quoted as the project wrote it
     * @param list<string> $appliesTo globs over project-relative paths; empty means every path
     */
    public function __construct(
        public string $name,
        public string $command,
        public array $appliesTo = [],
    ) {}

    /**
     * Whether this gate covers a file, so a brief lists only gates that bear on it.
     *
     * `fnmatch` runs without FNM_PATHNAME on purpose: a `*` then crosses `/`,
     * which lets `src/*` cover `src/Query/A.php` the way a project writing the
     * glob expects.
     */
    public function appliesTo(string $relativePath): bool
    {
        if ($this->appliesTo === []) {
            return true;
        }
        foreach ($this->appliesTo as $glob) {
            if (fnmatch($glob, $relativePath)) {
                return true;
            }
        }

        return false;
    }
}
