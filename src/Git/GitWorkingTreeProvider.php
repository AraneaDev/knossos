<?php

declare(strict_types=1);

namespace Knossos\Git;

/** Contract for reading uncommitted changes, so review can run against a dirty tree. */
interface GitWorkingTreeProvider
{
    /**
     * Return bounded changed paths and explicit renames without modifying Git,
     * and whether `HEAD` names a commit (`born`; false when the changes were
     * read against the empty tree). A reader that does not say is taken to
     * have read them against `HEAD`.
     *
     * @return array{paths: list<string>, renames: list<array{from: string, to: string}>, truncated: bool, born?: bool}
     *
     * @throws NotAWorkTree when the project root is in no git work tree
     */
    public function changes(string $projectRoot, ?string $baseRef, int $maxFiles, int $timeoutMs): array;
}
