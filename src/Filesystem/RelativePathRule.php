<?php

declare(strict_types=1);

namespace Knossos\Filesystem;

/**
 * The one rule for whether a path someone wrote stays inside the project.
 *
 * Lenient about spelling: empty segments, `.` segments, a trailing slash and
 * the empty root are all accepted, and a backslash counts as a separator. Strict
 * about escape: a leading slash, a drive letter, a NUL byte or a `..` segment
 * anywhere makes the path leave the root. Paths that must also be normalised
 * (evidence, worker inputs) go through
 * {@see \Knossos\Scanner\Protocol\RelativePath::assertValid()} instead, which
 * applies this rule and refuses every non-canonical spelling as well.
 */
final class RelativePathRule
{
    private function __construct() {}

    /** Whether `$path`, read from the project root, names something inside it. */
    public static function isContained(string $path): bool
    {
        if (str_contains($path, "\0")) {
            return false;
        }
        $normalised = str_replace('\\', '/', $path);
        if (str_starts_with($normalised, '/') || preg_match('/^[A-Za-z]:/', $normalised) === 1) {
            return false;
        }

        return !in_array('..', explode('/', $normalised), true);
    }
}
