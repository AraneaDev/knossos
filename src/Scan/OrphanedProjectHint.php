<?php

declare(strict_types=1);

namespace Knossos\Scan;

use PDO;

/**
 * Notices, on the first scan of a root, a project that looks like this one
 * before its checkout moved.
 *
 * A project id is its root path, so a moved checkout scans as a new project
 * while its notes stay on the old one, and nothing said so. This only reads:
 * it names `move-project` and leaves the decision to whoever runs it, because
 * two clones of one repository match each other just as well.
 */
final readonly class OrphanedProjectHint
{
    /** How many same-named orphans are compared, newest first; a first scan must stay cheap. */
    private const CANDIDATES = 5;

    public function __construct(private PDO $pdo) {}

    /**
     * The warning naming the best-matching orphan, or null when none matches.
     *
     * An orphan is a project whose root is gone, has the same basename as this
     * one, and holds notes; it matches when at least half the components its
     * notes name (and at least one) exist in this project's graph.
     */
    public function warning(string $projectId, string $rootRealpath): ?string
    {
        $statement = $this->pdo->prepare(
            "SELECT p.id, p.name, p.root_realpath FROM projects p WHERE p.id <> :project AND p.root_realpath NOT LIKE 'bundle://%' " .
            'AND EXISTS (SELECT 1 FROM annotations a WHERE a.project_id = p.id) ORDER BY p.updated_at DESC, p.id',
        );
        $statement->execute(['project' => $projectId]);
        $candidates = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $root = (string) $row['root_realpath'];
            if (basename($root) === basename($rootRealpath) && !file_exists($root)) {
                $candidates[] = $row;
            }
            if (count($candidates) === self::CANDIDATES) {
                break;
            }
        }

        $best = null;
        foreach ($candidates as $candidate) {
            [$present, $named] = $this->overlap((string) $candidate['id'], $projectId);
            if ($present < 1 || $present * 2 < $named) {
                continue;
            }
            $ratio = $present / $named;
            if ($best === null || $ratio > $best['ratio']) {
                $best = ['row' => $candidate, 'present' => $present, 'ratio' => $ratio];
            }
        }
        if ($best === null) {
            return null;
        }

        return sprintf(
            'Project %s at %s no longer exists and %d of its notes name components in this tree. To carry them over: knossos move-project %s %s --execute (MCP: move_project).',
            $best['row']['name'],
            $best['row']['root_realpath'],
            $best['present'],
            $best['row']['root_realpath'],
            $rootRealpath,
        );
    }

    /**
     * How many of the components an orphan's notes name exist in this graph, and how many it names.
     *
     * @return array{0: int, 1: int}
     */
    private function overlap(string $orphanId, string $projectId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) AS named, SUM(EXISTS (SELECT 1 FROM nodes n WHERE n.project_id = :project AND n.canonical_name = a.canonical_name)) AS present ' .
            'FROM (SELECT DISTINCT canonical_name FROM annotations WHERE project_id = :orphan) a',
        );
        $statement->execute(['project' => $projectId, 'orphan' => $orphanId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return [(int) ($row['present'] ?? 0), (int) ($row['named'] ?? 0)];
    }
}
