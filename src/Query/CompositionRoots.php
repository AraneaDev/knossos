<?php

declare(strict_types=1);

namespace Knossos\Query;

use Knossos\Store\ChunkedInQuery;
use PDO;

/**
 * The composition roots among a set of components: the places that build the
 * application's services, such as a container's wiring method or a class
 * holding one of each service. One constructs or injects at least
 * {@see self::MIN_SERVICES} classes and makes fewer calls into the project's
 * methods than half that, so a method that builds a few helpers on its way
 * through real work is not one.
 *
 * What reaches a change only through a composition root reaches it because it
 * builds the application, not because it uses the change, so a search can
 * report it apart (as {@see TestReachSearch} does).
 */
final class CompositionRoots
{
    /** Distinct classes constructed or injected before a component counts as wiring. */
    public const MIN_SERVICES = 6;

    /**
     * Which of `$nodeIds` are composition roots.
     *
     * @param list<string> $nodeIds
     * @return array<string, true>
     */
    public static function of(PDO $pdo, string $projectId, array $nodeIds): array
    {
        if ($nodeIds === []) {
            return [];
        }
        $sql = 'SELECT e.source_id FROM edges e JOIN nodes t ON t.id = e.target_id WHERE e.project_id = ? AND e.source_id IN (%s) '
            . "AND e.kind IN ('constructs', 'injects', 'calls') GROUP BY e.source_id "
            . "HAVING COUNT(DISTINCT CASE WHEN e.kind IN ('constructs', 'injects') THEN e.target_id END) >= CAST(? AS INTEGER) "
            . "AND 2 * COUNT(DISTINCT CASE WHEN e.kind = 'calls' AND t.kind = 'method' AND t.origin != 'derived' THEN e.target_id END) "
            . "< COUNT(DISTINCT CASE WHEN e.kind IN ('constructs', 'injects') THEN e.target_id END)";
        $roots = [];
        foreach (ChunkedInQuery::rows($pdo, $sql, array_values(array_unique($nodeIds)), [$projectId], [self::MIN_SERVICES], PDO::FETCH_COLUMN) as $id) {
            $roots[(string) $id] = true;
        }

        return $roots;
    }
}
