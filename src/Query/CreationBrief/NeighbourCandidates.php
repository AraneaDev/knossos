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
 * one role, the same grouping the folder facts count, or, for a subtree, every
 * file of that extension and role at or below the directory. Each file brings
 * the distinct targets it depends on outside its own folder.
 */
final readonly class NeighbourCandidates
{
    /** Edge kinds that make one file depend on another, as the folder facts count them. */
    private const EDGE_KINDS = "'injects', 'references', 'imports', 'constructs'";

    /** Node kinds that are members of a type, which are not dependencies on a type. */
    private const MEMBER_KINDS = "'method', 'external_method', 'property', 'variable'";

    /** Most files of a subtree weighed, so a brief whose basis is the whole project stays a bounded read. */
    public const MAX_SUBTREE_CANDIDATES = 200;

    public function __construct(private PDO $pdo) {}

    /**
     * The files of the population except `$exclude`, in path order.
     *
     * @param bool $subtree whether files in the directories below count too
     * @return list<array{path: string, uses: list<string>}>
     */
    public function of(string $projectId, string $directory, string $extension, string $role, string $exclude, bool $subtree = false): array
    {
        $files = $this->files($projectId, $directory, $extension, $exclude, $subtree);
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
        $population = array_filter($files, static fn(int|string $id): bool => isset($tests[$id]) === ($role === 'test'), ARRAY_FILTER_USE_KEY);
        if ($subtree) {
            $population = self::nearest($population, PathParts::directory($exclude));
        }
        $population = array_map('strval', array_keys($population));

        $uses = $this->uses($population, $files);
        $candidates = [];
        foreach ($population as $id) {
            $candidates[] = ['path' => $files[$id], 'uses' => $uses[$id] ?? []];
        }
        usort($candidates, static fn(array $a, array $b): int => strcmp($a['path'], $b['path']));

        return $candidates;
    }

    /**
     * The files with the extension directly in the directory, or anywhere below it for a subtree, by file id.
     *
     * @return array<string, string> relative path by file id
     */
    private function files(string $projectId, string $directory, string $extension, string $exclude, bool $subtree): array
    {
        $statement = $this->pdo->prepare('SELECT id, relative_path FROM files WHERE project_id = ? AND relative_path >= ? AND relative_path < ?');
        // Every path under `dir/` sorts between `dir/` and `dir0`, the character after the slash.
        $statement->execute($directory === '' ? [$projectId, '', "\u{10FFFF}"] : [$projectId, $directory . '/', $directory . '0']);
        $files = [];
        foreach ($statement->fetchAll(PDO::FETCH_NUM) as [$id, $path]) {
            $path = (string) $path;
            if ($path !== $exclude && ($subtree || PathParts::directory($path) === $directory) && PathParts::extension($path) === $extension) {
                $files[(string) $id] = $path;
            }
        }

        return $files;
    }

    /**
     * The MAX_SUBTREE_CANDIDATES files nearest a directory, cut on the paths alone before any edge is read.
     *
     * Nearest is the longest directory path shared with `$directory`, then the
     * path, so the same tree always keeps the same files. The files are already
     * of the wanted role, so tests beside the new file cannot crowd out its
     * sources (or the other way round).
     *
     * @param array<string, string> $files relative path by file id
     * @return array<string, string>
     */
    private static function nearest(array $files, string $directory): array
    {
        if (count($files) <= self::MAX_SUBTREE_CANDIDATES) {
            return $files;
        }
        $shared = [];
        foreach ($files as $id => $path) {
            $shared[$id] = self::sharedLength(PathParts::directory($path), $directory);
        }
        $ids = array_map('strval', array_keys($files));
        usort($ids, static fn(string $a, string $b): int => $shared[$b] <=> $shared[$a] ?: strcmp($files[$a], $files[$b]));

        return array_intersect_key($files, array_flip(array_slice($ids, 0, self::MAX_SUBTREE_CANDIDATES)));
    }

    /** The length of the leading directory path two directories share, whole segments only. */
    private static function sharedLength(string $a, string $b): int
    {
        $left = $a === '' ? [] : explode('/', $a);
        $right = $b === '' ? [] : explode('/', $b);
        $common = [];
        foreach ($left as $i => $segment) {
            if (($right[$i] ?? null) !== $segment) {
                break;
            }
            $common[] = $segment;
        }

        return strlen(implode('/', $common));
    }

    /**
     * What each file depends on outside its own folder, sorted.
     *
     * @param list<string> $fileIds
     * @param array<string, string> $paths relative path by file id
     * @return array<string, list<string>>
     */
    private function uses(array $fileIds, array $paths): array
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
            if ($targetPath === null || PathParts::directory((string) $targetPath) !== PathParts::directory($paths[(string) $fileId])) {
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
