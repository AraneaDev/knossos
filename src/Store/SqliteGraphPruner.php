<?php

declare(strict_types=1);

namespace Knossos\Store;

/**
 * Brings a project's stored graph in line with a rescan by difference.
 *
 * Reads what exists, deletes what the scan no longer produced, and restamps
 * what survived. The alternative, clearing and rewriting everything, cost the
 * size of the project on every rescan rather than the size of the change.
 */
final class SqliteGraphPruner
{
    public function __construct(private readonly SqliteStatementCache $statements) {}

    /**
     * The graph tables a scan owns and the column identifying a row in each,
     * child-first so a delete never orphans a row it has not reached yet.
     */
    private const GRAPH_TABLES = [
        'boundary_memberships' => 'boundary_id',
        'boundaries' => 'id',
        'classifications' => 'id',
        'edges' => 'id',
        'nodes' => 'id',
        'files' => 'id',
    ];

    /** The tables whose obsolete rows are found by primary key, see {@see self::pruneSql()}. */
    private const PRUNED_BY_PRIMARY_KEY = ['classifications', 'edges', 'nodes'];

    /**
     * The ids a project's graph currently holds, per table.
     *
     * Read before the scan's own rows are written, so the difference against
     * what the scan produced is exactly what no longer exists.
     *
     * @return array<string, array<string, true>> table name to id set
     */
    public function existingGraphIds(string $projectId): array
    {
        $ids = [];
        foreach (array_keys(self::GRAPH_TABLES) as $table) {
            if ($table === 'boundary_memberships') {
                continue;
            }
            $statement = $this->statements->prepare(sprintf('SELECT id FROM %s WHERE project_id = :project', $table));
            $statement->execute(['project' => $projectId]);
            $seen = [];
            while (($row = $statement->fetch()) !== false) {
                $seen[$row['id']] = true;
            }
            $ids[$table] = $seen;
        }
        // A membership is identified by the pair it joins, not by an id column.
        $statement = $this->statements->prepare('SELECT boundary_id, node_id FROM boundary_memberships WHERE project_id = :project');
        $statement->execute(['project' => $projectId]);
        $memberships = [];
        while (($row = $statement->fetch()) !== false) {
            $memberships[$row['boundary_id'] . "\0" . $row['node_id']] = true;
        }
        $ids['boundary_memberships'] = $memberships;

        return $ids;
    }

    /**
     * Delete the graph rows a scan did not produce, leaving the rest untouched.
     *
     * The alternative — clearing the project and writing every row back — cost
     * the size of the project on every rescan rather than the size of the
     * change. Child rows go first so the delete order is meaningful even though
     * a bulk transaction defers the foreign-key check to the commit.
     *
     * @param array<string, array<string, true>> $existing @param array<string, array<string, true>> $desired
     */
    public function pruneGraph(string $projectId, array $existing, array $desired): void
    {
        foreach (array_keys(self::GRAPH_TABLES) as $table) {
            $obsolete = array_keys(array_diff_key($existing[$table] ?? [], $desired[$table] ?? []));
            if ($obsolete === []) {
                continue;
            }
            if ($table === 'boundary_memberships') {
                $this->deleteMemberships($projectId, $obsolete);
                continue;
            }
            foreach (array_chunk($obsolete, 400) as $chunk) {
                $this->statements->prepare(self::pruneSql($table, $chunk))->execute([...$chunk, $projectId]);
            }
        }
    }

    /**
     * The delete for the obsolete `$ids` of `$table`, bound as the ids first, then the project.
     *
     * On the large tables the project guard carries a unary plus, which keeps
     * it as a filter but stops SQLite choosing it as the index. Left free,
     * the planner read every row of the project through a project index for
     * each chunk of 400 ids, so a delete cost the size of the project rather
     * than the size of the chunk. The small tables keep the plain guard: there
     * the plus turns the lookup into a scan of the whole table.
     *
     * @param list<string> $ids
     */
    public static function pruneSql(string $table, array $ids): string
    {
        return sprintf(
            'DELETE FROM %s WHERE id IN (%s) AND %sproject_id = ?',
            $table,
            implode(',', array_fill(0, count($ids), '?')),
            in_array($table, self::PRUNED_BY_PRIMARY_KEY, true) ? '+' : '',
        );
    }

    /**
     * Delete memberships by the pair they join, grouped so each delete can use the index.
     *
     * @param list<string> $pairs boundary id and node id joined by a NUL byte
     */
    private function deleteMemberships(string $projectId, array $pairs): void
    {
        $byBoundary = [];
        foreach ($pairs as $pair) {
            [$boundaryId, $nodeId] = explode("\0", $pair);
            $byBoundary[$boundaryId][] = $nodeId;
        }
        foreach ($byBoundary as $boundaryId => $nodeIds) {
            foreach (array_chunk($nodeIds, 400) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                $statement = $this->statements->prepare(sprintf(
                    'DELETE FROM boundary_memberships WHERE project_id = ? AND boundary_id = ? AND node_id IN (%s)',
                    $placeholders,
                ));
                $statement->execute([$projectId, $boundaryId, ...$chunk]);
            }
        }
    }

    /**
     * The tables whose every row is moved onto the scan that confirmed it.
     *
     * `files`, because "the files of the active scan" is a question the
     * freshness check and the project summary ask by `last_scan_id`, and
     * `boundaries`, which is a handful of rows. Not the large tables: see
     * {@see self::stampGraphScan()}.
     */
    public const STAMPED_TABLES = ['boundaries', 'files'];

    /** The tables whose rows stay on the scan that last wrote them, and so keep that scan's row alive. */
    public const WRITER_STAMPED_TABLES = ['boundary_memberships', 'classifications', 'edges', 'nodes'];

    /**
     * Attribute the surviving files and boundaries to the scan that just confirmed them.
     *
     * Nodes, edges, classifications and memberships are left on the scan
     * that last wrote them. Moving them too rewrote every row of the project
     * on every scan: the update itself was quick, but the pages it dirtied
     * were about 100 MB of write-ahead log for a graph of 105,000 edges,
     * however little the scan changed, and the commit paid for all of it.
     * Nothing reads those four columns as "confirmed by"; what they still
     * do is reference a scan row, so scan cleanup keeps a scan that any of
     * them names ({@see SqliteScanLifecycle}).
     */
    public function stampGraphScan(string $projectId, string $scanId): void
    {
        foreach (self::STAMPED_TABLES as $table) {
            $statement = $this->statements->prepare(sprintf('UPDATE %s SET last_scan_id = :scan WHERE project_id = :project AND last_scan_id <> :scan', $table));
            $statement->execute(['scan' => $scanId, 'project' => $projectId]);
        }
    }

    /** Drop a project's diagnostics, which belong to the scan that produced them. */
    public function clearProjectDiagnostics(string $projectId): void
    {
        $this->statements->prepare('DELETE FROM diagnostics WHERE project_id = :project')->execute(['project' => $projectId]);
    }
}
