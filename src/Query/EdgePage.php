<?php

declare(strict_types=1);

namespace Knossos\Query;

use Knossos\Scanner\Protocol\ConfidenceRank;
use Knossos\Store\EdgeQueries;
use PDO;

/**
 * One node's edges of the given kinds, strongest first, capped per node.
 *
 * The walks that read a node's edges one node at a time (flow forward,
 * impact backward) read them the same way: the edge row with its file and
 * both endpoint names, at or above a confidence rank, at most
 * {@see self::LIMIT} of them. A node with more had some dropped, and the
 * walk is told so through its truncation rather than claiming completeness.
 */
final class EdgePage
{
    /** The most edges read for one node; the query reads one more to tell a full page from a cut one. */
    public const LIMIT = 500;

    /** The reason a node's page recorded when it held more than {@see self::LIMIT} edges. */
    public const TRUNCATION_REASON = 'per_node_edge_limit';

    /**
     * The page of edges out of (Outgoing) or into (Incoming) `$nodeId`.
     *
     * An outgoing page also carries the target's row (`target_kind`,
     * `target_canonical_name`, `target_display_name`, `target_confidence`),
     * which is the node a forward walk moves to next.
     *
     * @param list<string> $kinds
     * @param bool $withMembers incoming only: also the edges into what `$nodeId` contains
     * @return list<array<string, mixed>>
     */
    public static function fetch(PDO $pdo, string $projectId, Direction $direction, string $nodeId, array $kinds, int $minRank, Truncation $truncation, bool $withMembers = false): array
    {
        $columns = 'e.*, ' . EdgeEvidencePath::sql() . ' AS relative_path, source.display_name AS source_name, target.display_name AS target_name';
        if ($direction === Direction::Outgoing) {
            $columns .= ', target.kind AS target_kind, target.canonical_name AS target_canonical_name, '
                . 'target.display_name AS target_display_name, target.confidence AS target_confidence';
            $anchor = 'e.source_id = ? ';
        } else {
            // The node and its members as one list of targets, each sought: an
            // OR of the two, or a list read per row, made SQLite read every
            // edge of the project and look its target up, 4 s for a class.
            $anchor = $withMembers
                ? "e.target_id IN (SELECT ? UNION ALL SELECT target_id FROM edges WHERE project_id = ? AND kind = 'contains' AND source_id = ?) "
                : 'e.target_id = ? ';
        }
        $statement = $pdo->prepare(
            'SELECT ' . $columns . ' ' .
            'FROM edges e JOIN nodes source ON source.id = e.source_id JOIN nodes target ON target.id = e.target_id ' .
            'LEFT JOIN files f ON f.id = e.file_id WHERE e.project_id = ? AND ' . $anchor .
            'AND ' . EdgeQueries::kindIn('e.kind', $kinds) . ' ' .
            'AND ' . ConfidenceRank::atLeast('e.confidence') . ' ' .
            'ORDER BY ' . ConfidenceRank::of('e.confidence') . ' DESC, e.kind, e.id LIMIT 501',
        );
        $anchorValues = $direction === Direction::Incoming && $withMembers ? [$nodeId, $projectId, $nodeId] : [$nodeId];
        $statement->execute([$projectId, ...$anchorValues, ...$kinds, $minRank]);
        $rows = $statement->fetchAll();
        [$rows, $cut] = Page::cut($rows, self::LIMIT);
        if ($cut) {
            $truncation->add(self::TRUNCATION_REASON);
        }

        return $rows;
    }
}
