<?php

declare(strict_types=1);

namespace Knossos\Query;

use Closure;
use Knossos\Store\ProjectRepository;
use PDO;

/**
 * The project a pane answer is for: the scanned project that contains a
 * path, or the `unscanned` envelope when none does.
 *
 * Every pane answer carries the same three leading fields, `path` (the path
 * canonicalised when it exists, as given otherwise), `project_id` and
 * `snapshot_id`, both null until a scanned project is found. A project row
 * without an active scan has no graph to answer from, so it is unscanned too.
 */
final class PaneProject
{
    /**
     * `$answer` for the scanned project that contains `$path`, or
     * `['status' => 'unscanned']` and `$envelope` when there is none.
     *
     * `$envelope` holds the fields every answer carries after `path`,
     * including null `project_id` and `snapshot_id` where they belong; `path`
     * is put in front of them. The answer gets the project and the envelope
     * with `project_id` and `snapshot_id` filled in place, so the fields keep
     * one order whatever the status.
     *
     * @param array<string, mixed> $envelope
     * @param Closure(array{id: string, root: string, snapshot_id: string}, array<string, mixed>): array<string, mixed> $answer
     *
     * @return array<string, mixed>
     */
    public static function answer(PDO $pdo, string $path, array $envelope, Closure $answer): array
    {
        $absolute = realpath($path) ?: $path;
        $envelope = ['path' => $absolute] + $envelope;
        $project = (new ProjectRepository($pdo))->resolvePath($absolute);
        $snapshot = $project['active_scan_id'] ?? null;
        if ($project === null || !is_string($snapshot) || $snapshot === '') {
            return ['status' => 'unscanned'] + $envelope;
        }
        $id = (string) $project['id'];
        $envelope['project_id'] = $id;
        $envelope['snapshot_id'] = $snapshot;

        return $answer(['id' => $id, 'root' => (string) $project['root_realpath'], 'snapshot_id' => $snapshot], $envelope);
    }
}
