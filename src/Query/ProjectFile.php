<?php

declare(strict_types=1);

namespace Knossos\Query;

use Knossos\Filesystem\RelativePathRule;

/** Whether a project-relative name is a regular file inside a project's root. */
final class ProjectFile
{
    private function __construct() {}

    /**
     * True for a relative path, without parent segments, naming a regular
     * file whose real path lies inside `$root`, so a symlink cannot point a
     * caller outside the project.
     */
    public static function exists(string $root, string $relative): bool
    {
        if ($root === '' || $relative === '' || !RelativePathRule::isContained($relative)) {
            return false;
        }
        $real = realpath(rtrim($root, '/') . '/' . $relative);
        $realRoot = realpath($root);

        return $real !== false && $realRoot !== false && is_file($real) && str_starts_with($real, rtrim($realRoot, '/') . '/');
    }
}
