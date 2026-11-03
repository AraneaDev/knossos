<?php

declare(strict_types=1);

namespace Knossos\Roots;

/**
 * Which folder a start would claim as a project, and whether it must ask first.
 *
 * A session started in a subfolder belongs to the repository around it, so the
 * walk goes up to the nearest `.git` (a directory, or the file a linked
 * worktree has) without running git. A folder that is no repository claims
 * itself. The answer is "ask" for the places nobody means to hand over whole:
 * the filesystem root, the home folder, anything above it, and a home folder
 * that happens to be a repository, because a dotfiles checkout makes every
 * subfolder of home look like a project.
 */
final class StartClaim
{
    /**
     * The root a start in a folder would claim, and whether to ask before claiming it.
     *
     * @param string|null $home the home folder, or null when it is unknown, which always asks
     * @return array{ask: bool, root: string}
     */
    public static function targetOf(string $dir, ?string $home): array
    {
        $start = realpath($dir);
        $start = $start === false ? AllowedRoots::normaliseRoot($dir) : $start;
        $root = $start;
        for ($current = $start;; $current = dirname($current)) {
            if (file_exists($current . '/.git')) {
                $root = $current;
                break;
            }
            if (dirname($current) === $current) {
                break;
            }
        }

        return ['ask' => self::broad($root, $home), 'root' => $root];
    }

    /** Whether a root is the filesystem root, the home folder, or a folder that contains it. */
    private static function broad(string $root, ?string $home): bool
    {
        if ($root === '/' || $home === null) {
            return true;
        }
        $resolved = realpath($home);
        if ($resolved === false) {
            return true;
        }

        return $root === $resolved || str_starts_with(rtrim($resolved, '/') . '/', rtrim($root, '/') . '/');
    }
}
