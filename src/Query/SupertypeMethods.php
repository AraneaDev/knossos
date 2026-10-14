<?php

declare(strict_types=1);

namespace Knossos\Query;

use Knossos\Store\ChunkedInQuery;
use PDO;

/**
 * The supertype methods a method fulfils: the methods of the same name that
 * the ancestors of its class declare, over `extends` and `implements`. A
 * call through an interface or a parent goes to the supertype method in the
 * graph, so a walk over dependants passes through these to reach the
 * implementation that may run (as {@see InheritanceGraph} lets dead-code
 * analysis keep such a method alive). {@see self::implementationsOf()} walks
 * the other way, for a flow that goes forward through such a call.
 *
 * A method's class is the class-like component that `contains` it. The
 * ancestor walk is bounded at {@see self::MAX_DEPTH} levels and stops on a
 * cycle; an external ancestor declares no members here and adds nothing.
 */
final class SupertypeMethods
{
    /** Bound on the ancestor walk, as {@see InheritanceGraph} bounds its own. */
    private const MAX_DEPTH = 20;

    /** The kinds whose methods can fulfil a supertype's. */
    private const CLASS_KINDS = ['class', 'interface', 'trait', 'enum'];

    /**
     * For each of `$nodeIds` that is a method fulfilling a supertype method,
     * the ids of the methods it fulfils.
     *
     * @param list<string> $nodeIds
     * @return array<string, list<string>> method id => supertype method ids
     */
    public static function of(PDO $pdo, string $projectId, array $nodeIds): array
    {
        return self::related($pdo, $projectId, $nodeIds, 'source_id', 'target_id');
    }

    /**
     * The inverse: for each of `$nodeIds` that is a method some subtype
     * implements or overrides, the ids of those methods, the ones a call to it
     * may run instead.
     *
     * @param list<string> $nodeIds
     * @return array<string, list<string>> method id => implementing method ids
     */
    public static function implementationsOf(PDO $pdo, string $projectId, array $nodeIds): array
    {
        return self::related($pdo, $projectId, $nodeIds, 'target_id', 'source_id');
    }

    /**
     * The methods of the same name in the classes related to each method's
     * class over `extends` and `implements`, walked from `$from` to `$to`:
     * child to parent for supertypes, parent to child for implementations.
     *
     * @param list<string> $nodeIds
     * @param 'source_id'|'target_id' $from
     * @param 'source_id'|'target_id' $to
     * @return array<string, list<string>>
     */
    private static function related(PDO $pdo, string $projectId, array $nodeIds, string $from, string $to): array
    {
        if ($nodeIds === []) {
            return [];
        }
        $kinds = "'" . implode("', '", self::CLASS_KINDS) . "'";
        // The class of each method, and its name.
        $owners = [];
        $sql = 'SELECT e.target_id, e.source_id, m.display_name FROM edges e JOIN nodes c ON c.id = e.source_id JOIN nodes m ON m.id = e.target_id '
            . "WHERE e.project_id = ? AND e.kind = 'contains' AND m.kind = 'method' AND c.kind IN ({$kinds}) AND e.target_id IN (%s)";
        foreach (ChunkedInQuery::rows($pdo, $sql, array_values(array_unique($nodeIds)), [$projectId], mode: PDO::FETCH_NUM) as [$method, $class, $name]) {
            $owners[(string) $method] = [(string) $class, (string) $name];
        }
        if ($owners === []) {
            return [];
        }
        // Every class's related classes, level by level from the methods' classes.
        $links = [];
        $frontier = array_values(array_unique(array_column($owners, 0)));
        $read = array_fill_keys($frontier, true);
        for ($level = 0; $level < self::MAX_DEPTH && $frontier !== []; ++$level) {
            $next = [];
            $sql = "SELECT {$from}, {$to} FROM edges WHERE project_id = ? AND kind IN ('extends', 'implements') AND {$from} IN (%s)";
            foreach (ChunkedInQuery::rows($pdo, $sql, $frontier, [$projectId], mode: PDO::FETCH_NUM) as [$class, $related]) {
                $links[(string) $class][] = (string) $related;
                if (!isset($read[(string) $related])) {
                    $read[(string) $related] = true;
                    $next[] = (string) $related;
                }
            }
            $frontier = $next;
        }
        $reached = [];
        foreach (array_unique(array_column($owners, 0)) as $class) {
            $reached[$class] = self::closure($class, $links);
        }
        // The related classes' methods, by name.
        $declared = [];
        $all = array_values(array_unique(array_merge(...array_values($reached) ?: [[]])));
        if ($all !== []) {
            $sql = 'SELECT e.source_id, e.target_id, m.display_name FROM edges e JOIN nodes m ON m.id = e.target_id '
                . "WHERE e.project_id = ? AND e.kind = 'contains' AND m.kind = 'method' AND e.source_id IN (%s)";
            foreach (ChunkedInQuery::rows($pdo, $sql, $all, [$projectId], mode: PDO::FETCH_NUM) as [$class, $method, $name]) {
                $declared[(string) $class][(string) $name][] = (string) $method;
            }
        }
        $matches = [];
        foreach ($owners as $method => [$class, $name]) {
            $found = [];
            foreach ($reached[$class] as $related) {
                array_push($found, ...($declared[$related][$name] ?? []));
            }
            $found = array_values(array_unique(array_diff($found, [$method])));
            if ($found !== []) {
                $matches[$method] = $found;
            }
        }

        return $matches;
    }

    /**
     * Every class `$class` reaches over `$links`, itself left out, each once however the hierarchy loops.
     *
     * @param array<string, list<string>> $links
     * @return list<string>
     */
    private static function closure(string $class, array $links): array
    {
        $seen = [$class => true];
        $stack = $links[$class] ?? [];
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
