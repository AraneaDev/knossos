<?php

declare(strict_types=1);

namespace Knossos\Query;

use InvalidArgumentException;

/**
 * A path a caller wrote, as the project-relative path the graph stores:
 * `./src/x.php`, `src/x.php` and the absolute path under the project root all
 * name `src/x.php`. Shared by every reader that takes a path from a caller, so
 * one spelling never finds a file another misses.
 */
final class ProjectRelativePath
{
    /** `$path` relative to `$root`; refused when it is empty or leaves the root. */
    public static function of(string $root, string $path): string
    {
        $trimmed = trim(str_replace('\\', '/', $path));
        $relative = str_starts_with($trimmed, '/')
            ? (str_starts_with($trimmed, $root . '/') ? substr($trimmed, strlen($root) + 1) : null)
            : preg_replace('#^(\./)+#', '', $trimmed);
        if ($relative === null || $relative === '' || in_array('..', explode('/', $relative), true)) {
            throw new InvalidArgumentException(sprintf('%s is outside the project (%s).', $path, $root));
        }

        return $relative;
    }
}
