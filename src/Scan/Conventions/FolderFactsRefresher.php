<?php

declare(strict_types=1);

namespace Knossos\Scan\Conventions;

use Knossos\Store\FolderFactsStore;
use PDO;
use Throwable;

/**
 * Brings a project's derived folder facts and companions up to date after a scan committed.
 *
 * Both are an aid to a later query, never part of what a scan promises, so a
 * failure in either is logged on one line and swallowed: the scan that
 * triggered it has already written its graph and must report that. The two
 * are guarded apart, so git being unavailable does not cost the facts.
 */
final readonly class FolderFactsRefresher
{
    private FolderCompanionsBuilder $companions;

    /** @param ?FolderCompanionsBuilder $companions injected only so a test can drive git without a subprocess */
    public function __construct(private PDO $pdo, ?FolderCompanionsBuilder $companions = null)
    {
        $this->companions = $companions ?? new FolderCompanionsBuilder(new FolderFactsStore($pdo));
    }

    /**
     * Rebuild the per-folder facts of `$projectId` from the committed graph, and its companions from git at `$gitHead`.
     */
    public function refresh(string $projectId, string $root, ?string $gitHead): void
    {
        try {
            (new FolderFactsStore($this->pdo))->replace($projectId, (new FolderFactsBuilder($this->pdo))->rows($projectId));
        } catch (Throwable $failure) {
            error_log('knossos folder facts: ' . $failure->getMessage());
        }
        try {
            $this->companions->refresh($projectId, $root, $gitHead);
        } catch (Throwable $failure) {
            error_log('knossos folder facts: ' . $failure->getMessage());
        }
    }
}
