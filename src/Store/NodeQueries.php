<?php

declare(strict_types=1);

namespace Knossos\Store;

use PDO;

/**
 * Node lookups keyed by an arbitrary set of ids, chunked through {@see ChunkedInQuery}.
 *
 * Each was written out at its callers with the same SQL and the same
 * row-to-map loop; a missing id is simply absent from the map.
 */
final class NodeQueries
{
    /**
     * The canonical names of the nodes among `$ids`, by id.
     *
     * @param list<string> $ids
     * @param int<1, max> $size ids bound per statement
     * @return array<string, string>
     */
    public static function canonicalNames(PDO $pdo, array $ids, int $size = ChunkedInQuery::SIZE): array
    {
        $names = [];
        foreach (ChunkedInQuery::rows($pdo, 'SELECT id, canonical_name FROM nodes WHERE id IN (%s)', $ids, mode: PDO::FETCH_NUM, size: $size) as [$id, $name]) {
            $names[(string) $id] = (string) $name;
        }

        return $names;
    }

    /**
     * Each node's names, kind and place (its file's relative path and first line), by id.
     *
     * @param list<string> $ids
     * @param int<1, max> $size ids bound per statement
     * @return array<string, array{display_name: ?string, canonical_name: string, kind: string, path: ?string, line: ?int}>
     */
    public static function places(PDO $pdo, array $ids, int $size = ChunkedInQuery::SIZE): array
    {
        $places = [];
        $sql = 'SELECT n.id, n.display_name, n.canonical_name, n.kind, f.relative_path, n.start_line FROM nodes n LEFT JOIN files f ON f.id = n.file_id WHERE n.id IN (%s)';
        foreach (ChunkedInQuery::rows($pdo, $sql, $ids, mode: PDO::FETCH_NUM, size: $size) as [$id, $display, $canonical, $kind, $path, $line]) {
            $places[(string) $id] = [
                'display_name' => $display === null ? null : (string) $display, 'canonical_name' => (string) $canonical, 'kind' => (string) $kind,
                'path' => $path === null ? null : (string) $path, 'line' => $line === null ? null : (int) $line,
            ];
        }

        return $places;
    }

    /**
     * The relative path of the file declaring each node among `$ids`, by id; a node without a file is absent.
     *
     * @param list<string> $ids
     * @param ?string $projectId only nodes of this project, when given
     * @return array<string, string>
     */
    public static function paths(PDO $pdo, array $ids, ?string $projectId = null): array
    {
        $sql = 'SELECT n.id, f.relative_path FROM nodes n JOIN files f ON f.id = n.file_id WHERE '
            . ($projectId === null ? '' : 'n.project_id = ? AND ') . 'n.id IN (%s) ORDER BY n.id';
        $paths = [];
        foreach (ChunkedInQuery::rows($pdo, $sql, $ids, $projectId === null ? [] : [$projectId], mode: PDO::FETCH_NUM) as [$id, $path]) {
            $paths[(string) $id] = (string) $path;
        }

        return $paths;
    }
}
