<?php

declare(strict_types=1);

namespace Knossos\Query;

use Knossos\Store\ChunkedInQuery;
use Knossos\Store\EnrichedFacts;
use PDO;

/**
 * A bounded walk over the native edges of some kinds, level by level, and
 * the transitive closure of what it read.
 *
 * Only native edges make a hop: an enricher's edge is a claim about the
 * code, not something the walk may extend itself over.
 */
final class EdgeWalk
{
    /**
     * The edges of `$kinds` read from `$start` in `$direction`, at most
     * `$maxDepth` levels out: each node read, mapped to the nodes one edge
     * away from it in the order the rows came, each node read once however
     * the edges loop. A node with no such edge has no entry.
     *
     * `$extraSql` narrows the edges further: a condition starting with
     * ` AND `, over the `edges` table by its name.
     *
     * @param list<string> $kinds
     * @param list<string> $start
     * @return array<string, list<string>>
     */
    public static function reach(PDO $pdo, string $projectId, array $kinds, Direction $direction, array $start, int $maxDepth, string $extraSql = ''): array
    {
        [$from, $to] = $direction === Direction::Outgoing ? ['source_id', 'target_id'] : ['target_id', 'source_id'];
        $sql = "SELECT {$from}, {$to} FROM edges WHERE project_id = ? AND kind IN ('" . implode("', '", $kinds) . "')"
            . EnrichedFacts::nativeCondition('edges') . $extraSql . " AND {$from} IN (%s)";
        $links = [];
        $frontier = array_values(array_unique($start));
        $queued = array_fill_keys($frontier, true);
        for ($depth = 0; $depth < $maxDepth && $frontier !== []; ++$depth) {
            $next = [];
            foreach (ChunkedInQuery::rows($pdo, $sql, $frontier, [$projectId], mode: PDO::FETCH_NUM) as [$node, $neighbour]) {
                $links[(string) $node][] = (string) $neighbour;
                if (!isset($queued[(string) $neighbour])) {
                    $queued[(string) $neighbour] = true;
                    $next[] = (string) $neighbour;
                }
            }
            $frontier = $next;
        }

        return $links;
    }

    /**
     * Every node `$node` reaches over `$links`, itself left out, each once
     * however the links loop, in the order a depth-first walk meets them. The
     * walk never enters a node of `$stops`.
     *
     * @param array<string, list<string>> $links
     * @param list<string> $stops
     * @return list<string>
     */
    public static function closure(string $node, array $links, array $stops = []): array
    {
        $seen = [$node => true] + array_fill_keys($stops, true);
        $stack = $links[$node] ?? [];
        $found = [];
        while ($stack !== []) {
            $next = array_pop($stack);
            if (isset($seen[$next])) {
                continue;
            }
            $seen[$next] = true;
            $found[] = $next;
            array_push($stack, ...($links[$next] ?? []));
        }

        return $found;
    }
}
