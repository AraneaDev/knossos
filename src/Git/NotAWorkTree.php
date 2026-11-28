<?php

declare(strict_types=1);

namespace Knossos\Git;

use RuntimeException;

/**
 * A project root git does not place in a work tree: `rev-parse
 * --is-inside-work-tree` failed, or answered "false" (git run inside a
 * `.git` directory).
 *
 * Its own type so a caller that reads "no git" as an answer (the session
 * pane's `no-git`) need not ask git the same question first.
 */
final class NotAWorkTree extends RuntimeException {}
