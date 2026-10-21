<?php

declare(strict_types=1);

namespace Knossos\Query;

use Knossos\Store\ChunkedInQuery;
use PDO;

/**
 * The supertype methods a method fulfils: the methods of the same name that
 * the ancestors of its class declare, over `extends`, `implements` and
 * `uses_trait`. A trait is a supertype here: a method the trait declares
 * (abstract or not) and calls on `$this` runs the using class's method of the
 * same name, so the class's method fulfils it. A call through an interface,
 * a parent or a trait goes to the supertype method in the graph, so a walk
 * over dependants passes through these to reach the
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

    /** The edges from a class to its supertypes. */
    private const EDGE_KINDS = ['extends', 'implements', 'uses_trait'];

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
        return self::related($pdo, $projectId, $nodeIds, Direction::Outgoing);
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
        return self::related($pdo, $projectId, $nodeIds, Direction::Incoming);
    }

    /**
     * The methods of the same name in the classes related to each method's
     * class over {@see self::EDGE_KINDS}, walked in `$direction`: outgoing,
     * child to parent, for supertypes; incoming, parent to child, for
     * implementations.
     *
     * @param list<string> $nodeIds
     * @return array<string, list<string>>
     */
    private static function related(PDO $pdo, string $projectId, array $nodeIds, Direction $direction): array
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
        $links = EdgeWalk::reach($pdo, $projectId, self::EDGE_KINDS, $direction, array_values(array_unique(array_column($owners, 0))), self::MAX_DEPTH);
        $reached = [];
        foreach (array_unique(array_column($owners, 0)) as $class) {
            $reached[$class] = EdgeWalk::closure($class, $links);
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
}
