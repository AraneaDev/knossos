<?php

declare(strict_types=1);

namespace Knossos\Query;

use Knossos\Store\ChunkedInQuery;
use PDO;

/**
 * The classes whose construction runs a constructor: the class that declares
 * it, and every subclass that declares none of its own and so inherits it.
 *
 * `new Foo()` is recorded as a `constructs` edge into the class `Foo`, never
 * into `Foo::__construct`, so nothing in the graph points at a constructor.
 * A walk over dependants that meets one passes through to these classes and
 * reads only their `constructs` edges: a type named in a signature constructs
 * nothing, and must not count as running the constructor. The same way
 * {@see SupertypeMethods} passes a walk through to the methods a call through
 * an interface lands on.
 *
 * The subclass walk is bounded at {@see self::MAX_DEPTH} levels and stops on
 * a cycle. Only a native `extends` edge makes a hop: an enricher's claim that
 * a class extends another runs no constructor.
 */
final class ConstructedClasses
{
    /** The edge kind that runs a constructor: the only one a walk passed through here reads. */
    public const EDGE_KIND = 'constructs';

    /** What each language calls the method construction runs. */
    private const CONSTRUCTOR_NAMES = ['__construct', 'constructor', '__init__'];

    /** Bound on the subclass walk, as {@see SupertypeMethods} bounds its own. */
    private const MAX_DEPTH = 20;

    /**
     * For each of `$nodeIds` that is a constructor, the ids of the classes
     * whose construction runs it.
     *
     * @param list<string> $nodeIds
     * @return array<string, list<string>> constructor id => class ids
     */
    public static function of(PDO $pdo, string $projectId, array $nodeIds): array
    {
        if ($nodeIds === []) {
            return [];
        }
        $names = "'" . implode("', '", self::CONSTRUCTOR_NAMES) . "'";
        $owners = [];
        $sql = 'SELECT e.target_id, e.source_id FROM edges e JOIN nodes c ON c.id = e.source_id JOIN nodes m ON m.id = e.target_id '
            . "WHERE e.project_id = ? AND e.kind = 'contains' AND m.kind = 'method' AND m.display_name IN ({$names}) AND c.kind = 'class' AND e.target_id IN (%s)";
        foreach (ChunkedInQuery::rows($pdo, $sql, array_values(array_unique($nodeIds)), [$projectId], mode: PDO::FETCH_NUM) as [$constructor, $class]) {
            $owners[(string) $constructor] = (string) $class;
        }
        $classes = [];
        foreach ($owners as $constructor => $class) {
            $classes[$constructor] = self::inheriting($pdo, $projectId, $class, $names);
        }

        return $classes;
    }

    /**
     * `$class` and its subclasses that declare no constructor, level by level.
     *
     * @return list<string>
     */
    private static function inheriting(PDO $pdo, string $projectId, string $class, string $names): array
    {
        // A subclass with a constructor of its own runs that one, and so do its subclasses.
        $inherits = " AND EXISTS (SELECT 1 FROM nodes c WHERE c.id = edges.source_id AND c.kind = 'class') "
            . "AND NOT EXISTS (SELECT 1 FROM edges own JOIN nodes m ON m.id = own.target_id WHERE own.project_id = edges.project_id AND own.source_id = edges.source_id AND own.kind = 'contains' AND m.kind = 'method' AND m.display_name IN ({$names}))";
        $children = EdgeWalk::reach($pdo, $projectId, ['extends'], Direction::Incoming, [$class], self::MAX_DEPTH, $inherits);

        return array_keys([$class => true] + array_fill_keys(array_merge(...array_values($children) ?: [[]]), true));
    }
}
