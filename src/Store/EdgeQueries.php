<?php

declare(strict_types=1);

namespace Knossos\Store;

use PDO;

/**
 * SQL fragments for filtering edges, written out alike at each query that
 * reads them, and the index hint for reading them in source order.
 *
 * Origin filters live in {@see EnrichedFacts}; this holds the rest.
 */
final class EdgeQueries
{
    /** How many of a project's edges {@see self::sourceOrderHint()} looks at. */
    private const SAMPLE = 2048;

    /**
     * `<column> IN (?, ...)`, one placeholder per kind, for the caller to bind the kinds in order.
     *
     * @param list<string> $kinds
     */
    public static function kindIn(string $column, array $kinds): string
    {
        return sprintf('%s IN (%s)', $column, implode(',', array_fill(0, count($kinds), '?')));
    }

    /**
     * {@see self::kindIn()} checked on each row rather than sought in an index,
     * for a read that already seeks one source or target.
     *
     * The unary `+` keeps `kind` out of the index key. Without it SQLite seeks
     * (project, target, kind) once per kind, 23 by default, where one seek
     * of (project, target) reaches the few edges a node has; over 50,000
     * nodes that was 1.5 s against 0.2 s. A read of every edge of a kind
     * across the project wants the kind index, and keeps {@see self::kindIn()}.
     *
     * @param list<string> $kinds
     */
    public static function kindFilteredIn(string $column, array $kinds): string
    {
        return '+' . self::kindIn($column, $kinds);
    }

    /**
     * The index hint for reading a project's edges of `$kinds` in source order:
     * `INDEXED BY edges_project_source_idx` when those kinds are a fair share
     * of the project's edges, else `INDEXED BY edges_project_kind_idx`.
     *
     * In source order the source index gives most of the order, and SQLite
     * sorts each source's few edges alone. Through the kind index it sorts
     * every selected edge before the first arrives, spilled to a temporary
     * file: 1.4 s on 85,000 edges, where the source index took none. Which one
     * SQLite picks turns on how its parameters are bound, not on the data. Few
     * selected edges are the other way round: the kind index reaches them
     * alone, where the source index walks every edge of the project.
     *
     * The share is judged from the first {@see self::SAMPLE} entries of the
     * source index, which run through the sources by id, a hash, so they stand
     * for the whole: under a millisecond. The hint changes how the edges are
     * read, never which, nor their order.
     *
     * @param list<string> $kinds
     */
    public static function sourceOrderHint(PDO $pdo, string $projectId, array $kinds): string
    {
        $sample = $pdo->prepare(sprintf(
            'SELECT COUNT(*), COALESCE(SUM(kind IN (%s)), 0) FROM (SELECT kind FROM edges INDEXED BY edges_project_source_idx WHERE project_id = ? LIMIT %d)',
            implode(',', array_fill(0, count($kinds), '?')),
            self::SAMPLE,
        ));
        $sample->execute([...$kinds, $projectId]);
        [$sampled, $selected] = array_map('intval', (array) $sample->fetch(PDO::FETCH_NUM));

        // Named either way: left to itself, SQLite's choice for a narrow
        // selection differs between releases (3.46 walks the source index).
        // A project too small to fill the sample is read quickly either way.
        return $sampled >= self::SAMPLE && $selected * 10 >= $sampled ? 'INDEXED BY edges_project_source_idx' : 'INDEXED BY edges_project_kind_idx';
    }
}
