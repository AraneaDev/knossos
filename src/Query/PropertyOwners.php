<?php

declare(strict_types=1);

namespace Knossos\Query;

use Knossos\Store\ChunkedInQuery;
use PDO;

/**
 * The class that declares each property.
 *
 * A class's methods read its properties (`$this->helper->shine()`), but the
 * graph records the call into what a property holds, never the read of the
 * property, so nothing points at one. A walk over dependants that meets a
 * property passes through to its class: whatever uses the class may use the
 * property. That over-approximates the way {@see SupertypeMethods} does, so
 * such a hop is probable at best.
 */
final class PropertyOwners
{
    /** The kinds a property's owner may be. */
    private const OWNER_KINDS = ['class', 'trait', 'enum', 'interface'];

    /**
     * For each of `$nodeIds` that is a property, the id of the class that declares it.
     *
     * @param list<string> $nodeIds
     * @return array<string, string> property id => class id
     */
    public static function of(PDO $pdo, string $projectId, array $nodeIds): array
    {
        if ($nodeIds === []) {
            return [];
        }
        $kinds = "'" . implode("', '", self::OWNER_KINDS) . "'";
        $owners = [];
        $sql = 'SELECT e.target_id, e.source_id FROM edges e JOIN nodes c ON c.id = e.source_id JOIN nodes p ON p.id = e.target_id '
            . "WHERE e.project_id = ? AND e.kind = 'contains' AND p.kind = 'property' AND c.kind IN ({$kinds}) AND e.target_id IN (%s)";
        foreach (ChunkedInQuery::rows($pdo, $sql, array_values(array_unique($nodeIds)), [$projectId], mode: PDO::FETCH_NUM) as [$property, $class]) {
            $owners[(string) $property] = (string) $class;
        }

        return $owners;
    }
}
