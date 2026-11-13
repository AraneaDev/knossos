<?php

declare(strict_types=1);

namespace Knossos\Query\CreationBrief;

use Knossos\Scan\Conventions\PathParts;
use Knossos\Store\ChunkedInQuery;
use PDO;

/**
 * Reads the files of one folder population from the graph, as {@see Neighbours::rank()} wants them.
 *
 * A population is the direct children of a directory with one extension and
 * one role, the same grouping the folder facts count. Each file brings the
 * distinct targets it depends on outside its own folder.
 */
final readonly class NeighbourCandidates
{
    /** Edge kinds that make one file depend on another, as the folder facts count them. */
    private const EDGE_KINDS = "'injects', 'references', 'imports', 'constructs'";

    /** Node kinds that are members of a type, which are not dependencies on a type. */
    private const MEMBER_KINDS = "'method', 'external_method', 'property', 'variable'";

    public function __construct(private PDO $pdo) {}

    /**
     * The files of the population except `$exclude`, in path order.
     *
     * @return list<array{path: string, uses: list<string>}>
     */
    public function of(string $projectId, string $directory, string $extension, string $role, string $exclude): array
    {
        $files = $this->files($projectId, $directory, $extension, $exclude);
        if ($files === []) {
            return [];
        }
        $ids = array_keys($files);
        $tests = [];
        foreach (ChunkedInQuery::rows(
            $this->pdo,
            "SELECT DISTINCT n.file_id FROM classifications c JOIN nodes n ON n.id = c.node_id WHERE c.role = 'quality.test_module' AND c.project_id = ? AND n.file_id IN (%s)",
            $ids,
            [$projectId],
            mode: PDO::FETCH_COLUMN,
        ) as $fileId) {
            $tests[(string) $fileId] = true;
        }
        $population = array_values(array_filter($ids, static fn(string $id): bool => isset($tests[$id]) === ($role === 'test')));

        $uses = $this->uses($population, $directory);
        $candidates = [];
        foreach ($population as $id) {
            $candidates[] = ['path' => $files[$id], 'uses' => $uses[$id] ?? []];
        }
        usort($candidates, static fn(array $a, array $b): int => strcmp($a['path'], $b['path']));

        return $candidates;
    }

    /**
     * The direct children of the directory with the extension, by file id.
     *
     * @return array<string, string> relative path by file id
     */
    private function files(string $projectId, string $directory, string $extension, string $exclude): array
    {
        $statement = $this->pdo->prepare('SELECT id, relative_path FROM files WHERE project_id = ? AND relative_path >= ? AND relative_path < ?');
        // Every path under `dir/` sorts between `dir/` and `dir0`, the character after the slash.
        $statement->execute($directory === '' ? [$projectId, '', "\u{10FFFF}"] : [$projectId, $directory . '/', $directory . '0']);
        $files = [];
        foreach ($statement->fetchAll(PDO::FETCH_NUM) as [$id, $path]) {
            $path = (string) $path;
            if ($path !== $exclude && PathParts::directory($path) === $directory && PathParts::extension($path) === $extension) {
                $files[(string) $id] = $path;
            }
        }

        return $files;
    }

    /**
     * What each file depends on outside its own folder, sorted.
     *
     * @param list<string> $fileIds
     * @return array<string, list<string>>
     */
    private function uses(array $fileIds, string $directory): array
    {
        $uses = [];
        foreach (ChunkedInQuery::rows(
            $this->pdo,
            'SELECT DISTINCT s.file_id, t.canonical_name, tf.relative_path FROM edges e JOIN nodes s ON s.id = e.source_id JOIN nodes t ON t.id = e.target_id
             LEFT JOIN files tf ON tf.id = t.file_id
             WHERE e.kind IN (' . self::EDGE_KINDS . ') AND t.kind NOT IN (' . self::MEMBER_KINDS . ') AND s.file_id IN (%s)',
            $fileIds,
            mode: PDO::FETCH_NUM,
        ) as [$fileId, $target, $targetPath]) {
            if ($targetPath === null || PathParts::directory((string) $targetPath) !== $directory) {
                $uses[(string) $fileId][(string) $target] = (string) $target;
            }
        }
        foreach ($uses as $id => $targets) {
            $list = array_values($targets);
            sort($list, SORT_STRING);
            $uses[$id] = $list;
        }

        return $uses;
    }
}
