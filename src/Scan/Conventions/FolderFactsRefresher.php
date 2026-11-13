<?php

declare(strict_types=1);

namespace Knossos\Scan\Conventions;

use Knossos\Store\FolderFactsStore;
use PDO;
use Throwable;

/**
 * Brings a project's derived folder facts up to date after a scan committed.
 *
 * The facts are an aid to a later query, never part of what a scan promises, so
 * a failure here is logged on one line and swallowed: the scan that triggered
 * it has already written its graph and must report that.
 */
final readonly class FolderFactsRefresher
{
    public function __construct(private PDO $pdo) {}

    /** Rebuild the per-folder facts of `$projectId` from the committed graph. */
    public function refresh(string $projectId): void
    {
        try {
            (new FolderFactsStore($this->pdo))->replace($projectId, (new FolderFactsBuilder($this->pdo))->rows($projectId));
        } catch (Throwable $failure) {
            error_log('knossos folder facts: ' . $failure->getMessage());
        }
    }
}
