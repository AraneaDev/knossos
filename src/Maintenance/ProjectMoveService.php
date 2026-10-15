<?php

declare(strict_types=1);

namespace Knossos\Maintenance;

use InvalidArgumentException;
use Knossos\Discovery\{AllowedRoots, RootsFile};
use Knossos\Result\ResultEnvelope;
use Knossos\Scan\{ProjectScanService, ProjectScanner};
use PDO;
use Throwable;

/**
 * Carries a moved checkout's annotations to the project its new root scans as.
 *
 * A project id is the hash of its root path, and every graph id is derived
 * from it, so a checkout that moves scans as a new, empty project while its
 * notes stay on the old id where nothing reads them. Only the annotations are
 * carried: they are the one thing a scan cannot rebuild, and they are keyed by
 * component name and root-relative evidence paths, which a move leaves alone.
 * The graph, its snapshots and its history start over with the new scan.
 *
 * Preview by default, like every command that removes a project.
 */
final readonly class ProjectMoveService
{
    /** How many conflicts a result lists; the total is always reported. */
    private const CONFLICTS_LISTED = 50;

    public function __construct(private PDO $pdo, private DatabaseMaintenanceService $maintenance) {}

    /**
     * Move, or preview moving, the annotations of the project at `$from` to the
     * project `$to` scans as, then remove the old project.
     *
     * @param string $from the old root as recorded, or the old project id
     * @param string $to the directory the checkout lives in now
     * @param RootsFile|null $roots the allow-list to swap the old root for the
     *        new one in; null leaves it alone, which is what a tool call passes
     */
    public function move(string $from, string $to, bool $execute, ProjectScanner $scanner, ?RootsFile $roots = null): ResultEnvelope
    {
        $source = $this->source($from);
        $target = realpath($to);
        if ($target === false || !is_dir($target)) {
            throw new InvalidArgumentException(sprintf('MOVE_TARGET_NOT_DIRECTORY: %s is not an existing directory.', $to));
        }
        $targetId = ProjectScanService::projectIdOf($target);
        if ($targetId === $source['id']) {
            throw new InvalidArgumentException(sprintf('MOVE_SAME_PROJECT: %s already scans as project %s.', $target, $targetId));
        }
        $targetExists = $this->projectExists($targetId);
        $counts = $this->counts($source['id']);
        $conflicts = $this->conflicts($source['id'], $targetId);
        $data = [
            'executed' => false,
            'from' => $source,
            'to' => ['root' => $target, 'project_id' => $targetId, 'exists' => $targetExists],
            'annotations' => $counts,
            'conflicts' => ['total' => count($conflicts), 'items' => array_slice($conflicts, 0, self::CONFLICTS_LISTED)],
            'roots' => $roots === null ? null : self::rootsChange($roots, $source['root'], $target),
            'scanned' => false,
            'removed_project' => null,
        ];
        if (!$execute) {
            return new ResultEnvelope($source['id'], '', sprintf('Dry run: %d annotations would move from %s to %s.', $counts['total'], $source['root'], $target), $data, warnings: [
                sprintf('Set execute=true to move %d annotations and remove the old project.', $counts['total']),
            ]);
        }

        $data['executed'] = true;
        $data['roots'] = $roots?->replace($source['root'], $target);
        if (!$targetExists) {
            $scanner->scan($target);
            $data['scanned'] = true;
        }
        $this->rekey($source['id'], $targetId, $conflicts);
        $warnings = [];
        try {
            $this->maintenance->removeProject($source['id'], true);
            $data['removed_project'] = $source['id'];
        } catch (Throwable $error) {
            $warnings[] = sprintf('Notes moved; the old project could not be removed (%s). Finish with remove_project %s execute=true.', $error->getMessage(), $source['id']);
        }

        return new ResultEnvelope($targetId, '', sprintf('Moved %d annotations from %s to %s.', $counts['total'], $source['root'], $target), $data, warnings: $warnings);
    }

    /**
     * The project being moved, refused unless its checkout is really gone.
     *
     * @return array{id: string, name: string, root: string}
     */
    private function source(string $from): array
    {
        $statement = str_starts_with($from, 'project_')
            ? $this->pdo->prepare('SELECT id, name, root_realpath FROM projects WHERE id = :value')
            : $this->pdo->prepare('SELECT id, name, root_realpath FROM projects WHERE root_realpath = :value');
        $statement->execute(['value' => str_starts_with($from, 'project_') ? $from : AllowedRoots::normaliseRoot($from)]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new InvalidArgumentException(sprintf('PROJECT_NOT_FOUND: no project has root or id %s.', $from));
        }
        $root = (string) $row['root_realpath'];
        if (str_starts_with($root, 'bundle://')) {
            throw new InvalidArgumentException(sprintf('MOVE_SOURCE_IMPORTED: project %s was imported from a bundle and has no checkout to move.', $row['id']));
        }
        // Still on disk means a second clone rather than a move, and moving
        // would strip the first checkout of its notes.
        if (file_exists($root)) {
            throw new InvalidArgumentException(sprintf('MOVE_SOURCE_STILL_EXISTS: %s still exists, so the target is a second checkout, not a move; scan it on its own.', $root));
        }

        return ['id' => (string) $row['id'], 'name' => (string) $row['name'], 'root' => $root];
    }

    /** Whether the target root has been scanned yet, which decides whether execute scans it first. */
    private function projectExists(string $projectId): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM projects WHERE id = :id');
        $statement->execute(['id' => $projectId]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * How many annotations the project holds, in all and by author and kind.
     *
     * @return array{total: int, by_author: array{user: int, model: int}, by_kind: array<string, int>}
     */
    private function counts(string $projectId): array
    {
        $statement = $this->pdo->prepare('SELECT author, kind, COUNT(*) AS n FROM annotations WHERE project_id = :project GROUP BY author, kind ORDER BY kind');
        $statement->execute(['project' => $projectId]);
        $counts = ['total' => 0, 'by_author' => ['user' => 0, 'model' => 0], 'by_kind' => []];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $count = (int) $row['n'];
            $counts['total'] += $count;
            $counts['by_author'][(string) $row['author']] = ($counts['by_author'][(string) $row['author']] ?? 0) + $count;
            $counts['by_kind'][(string) $row['kind']] = ($counts['by_kind'][(string) $row['kind']] ?? 0) + $count;
        }

        return $counts;
    }

    /**
     * Annotations both projects hold for the same component, kind and author,
     * with the side that is kept: the later edit, or the target's on a tie.
     *
     * @return list<array{canonical_name: string, kind: string, author: string, kept: 'from'|'to'}>
     */
    private function conflicts(string $sourceId, string $targetId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT s.canonical_name, s.kind, s.author, s.updated_at AS source_at, t.updated_at AS target_at ' .
            'FROM annotations s JOIN annotations t ON t.project_id = :target AND t.canonical_name = s.canonical_name ' .
            'AND t.kind = s.kind AND t.author = s.author WHERE s.project_id = :source ORDER BY s.canonical_name, s.kind, s.author',
        );
        $statement->execute(['source' => $sourceId, 'target' => $targetId]);
        $conflicts = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $conflicts[] = [
                'canonical_name' => (string) $row['canonical_name'],
                'kind' => (string) $row['kind'],
                'author' => (string) $row['author'],
                'kept' => strcmp((string) $row['source_at'], (string) $row['target_at']) > 0 ? 'from' : 'to',
            ];
        }

        return $conflicts;
    }

    /**
     * Settle each conflict, then hand every remaining source row to the target.
     * One transaction: a failure leaves both projects as they were, so the
     * command can simply be run again.
     *
     * @param list<array{canonical_name: string, kind: string, author: string, kept: 'from'|'to'}> $conflicts
     */
    private function rekey(string $sourceId, string $targetId, array $conflicts): void
    {
        $delete = $this->pdo->prepare('DELETE FROM annotations WHERE project_id = :project AND canonical_name = :name AND kind = :kind AND author = :author');
        $this->pdo->beginTransaction();
        try {
            foreach ($conflicts as $conflict) {
                $delete->execute([
                    'project' => $conflict['kept'] === 'from' ? $targetId : $sourceId,
                    'name' => $conflict['canonical_name'],
                    'kind' => $conflict['kind'],
                    'author' => $conflict['author'],
                ]);
            }
            $this->pdo->prepare('UPDATE annotations SET project_id = :target WHERE project_id = :source')
                ->execute(['target' => $targetId, 'source' => $sourceId]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        }
    }

    /**
     * What swapping the old root for the new one would change, without writing.
     *
     * @return array{added: bool, removed: bool}
     */
    private static function rootsChange(RootsFile $roots, string $old, string $new): array
    {
        $current = array_map(AllowedRoots::normaliseRoot(...), $roots->read());

        return [
            'added' => !in_array(AllowedRoots::normaliseRoot($new), $current, true),
            'removed' => in_array(AllowedRoots::normaliseRoot($old), $current, true),
        ];
    }
}
