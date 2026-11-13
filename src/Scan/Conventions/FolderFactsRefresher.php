<?php

declare(strict_types=1);

namespace Knossos\Scan\Conventions;

use Knossos\Scan\StageClock;
use Knossos\Store\FolderFactsStore;
use PDO;
use Throwable;

/**
 * Brings a project's derived folder facts and companions up to date after a scan committed.
 *
 * Both are an aid to a later query, never part of what a scan promises, so a
 * failure in either is logged on one line and swallowed: the scan that
 * triggered it has already written its graph and must report that. The two
 * are guarded apart, so git being unavailable does not cost the facts. Each
 * is timed as its own stage of the scan, `folder_facts` and `folder_companions`.
 */
final readonly class FolderFactsRefresher
{
    private FolderCompanionsBuilder $companions;

    private FolderFactsStore $store;

    /** @param ?FolderCompanionsBuilder $companions injected only so a test can drive git without a subprocess */
    public function __construct(private PDO $pdo, ?FolderCompanionsBuilder $companions = null)
    {
        $this->store = new FolderFactsStore($pdo);
        $this->companions = $companions ?? new FolderCompanionsBuilder($this->store);
    }

    /**
     * After a scan that changed the graph: rebuild the per-folder facts of `$projectId`
     * from the committed graph, and its companions from git at `$gitHead`.
     */
    public function refresh(string $projectId, string $root, ?string $gitHead, StageClock $clock = new StageClock()): void
    {
        $this->rebuildFacts($projectId, $clock);
        $this->refreshCompanions($projectId, $root, $gitHead, $clock);
    }

    /**
     * After a scan that found nothing changed: the graph the facts describe is the
     * one already stored, so they are built only when the project has none (a
     * database from before folder facts existed). The companions follow the commit,
     * which can move without any file changing, so they are always brought up to
     * `$gitHead`; that costs one SELECT when it has not moved.
     */
    public function refreshUnchanged(string $projectId, string $root, ?string $gitHead, StageClock $clock = new StageClock()): void
    {
        try {
            $missing = !$this->store->has($projectId);
        } catch (Throwable $failure) {
            error_log('knossos folder facts: ' . $failure->getMessage());
            $missing = false;
        }
        if ($missing) {
            $this->rebuildFacts($projectId, $clock);
        }
        $this->refreshCompanions($projectId, $root, $gitHead, $clock);
    }

    /** Replace the stored facts with ones built from the committed graph, timed as `folder_facts`. */
    private function rebuildFacts(string $projectId, StageClock $clock): void
    {
        $clock->time('folder_facts', function () use ($projectId): void {
            try {
                $this->store->replace($projectId, (new FolderFactsBuilder($this->pdo))->rows($projectId));
            } catch (Throwable $failure) {
                error_log('knossos folder facts: ' . $failure->getMessage());
            }
        });
    }

    /** Bring the companions up to `$gitHead`, timed as `folder_companions`. */
    private function refreshCompanions(string $projectId, string $root, ?string $gitHead, StageClock $clock): void
    {
        $clock->time('folder_companions', function () use ($projectId, $root, $gitHead): void {
            try {
                $this->companions->refresh($projectId, $root, $gitHead);
            } catch (Throwable $failure) {
                error_log('knossos folder facts: ' . $failure->getMessage());
            }
        });
    }
}
