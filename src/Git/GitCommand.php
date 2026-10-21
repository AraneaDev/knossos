<?php

declare(strict_types=1);

namespace Knossos\Git;

use RuntimeException;

/**
 * The one way a git command starts here: the binary and the options every
 * call shares, then `-C` and the directory it runs in.
 *
 * - `core.quotePath=false`: a path outside ASCII is printed as its bytes, not
 *   as an octal-escaped, quoted string no caller would parse back.
 * - `--no-optional-locks`: a read never takes the index lock a user's own git
 *   command may be waiting for.
 * - `--no-pager`: output goes to the pipe, never to a pager.
 * - `--literal-pathspecs`: a path is named by itself, never read as a glob
 *   or a pathspec magic over other paths. No call here asks git to match a
 *   pattern, so every pathspec is a path a file or the project has.
 */
final class GitCommand
{
    /**
     * The command prefix for running git in `$root`; the subcommand and its
     * arguments follow.
     *
     * @return non-empty-list<string>
     */
    public static function in(string $root): array
    {
        return ['git', '-c', 'core.quotePath=false', '--no-optional-locks', '--no-pager', '--literal-pathspecs', '-C', $root];
    }

    /**
     * The prefix for a provider's query against `$projectRoot`, once the
     * timeout is within 1 to 5000 ms and the root resolves to a readable
     * directory; the prefix names the resolved root.
     *
     * @return non-empty-list<string>
     *
     * @throws RuntimeException when either is not
     */
    public static function checked(string $projectRoot, int $timeoutMs): array
    {
        if ($timeoutMs < 1 || $timeoutMs > 5000) {
            throw new RuntimeException('timeout_ms must be between 1 and 5000.');
        }
        $root = realpath($projectRoot);
        if ($root === false || !is_dir($root)) {
            throw new RuntimeException('Git project root is not a readable directory.');
        }

        return self::in($root);
    }
}
