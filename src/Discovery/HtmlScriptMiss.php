<?php

declare(strict_types=1);

namespace Knossos\Discovery;

/**
 * A local script an HTML page loads that names no single scanned file, and why.
 *
 * `reason` is one of:
 * - `none`: no candidate path is a scanned file;
 * - `ambiguous`: more than one is, so choosing would be a guess;
 * - `mapped-missing`: a configured web root maps the URL to a path that is not a scanned file;
 * - `outside`: the path climbs above the project root.
 *
 * `candidates` holds the files that matched for `ambiguous`, and the paths
 * tried otherwise (none for `outside`).
 */
final readonly class HtmlScriptMiss
{
    /** @param list<string> $candidates */
    public function __construct(
        public string $reason,
        public array $candidates = [],
    ) {}
}
