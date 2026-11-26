<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\Facts;

use Knossos\Scanner\Protocol\NodeFact;
use Knossos\TestFidelity\TestFidelityGraph;

/**
 * A type's whole field list: its own and every field it inherits, composed
 * over the scan's graph as `member_names` is (protocol "Field lists").
 *
 * A worker that reads one file at a time sends a type's own fields only, so
 * the inherited ones come from the types it extends and the traits it uses.
 * The list is whole only when every such ancestor is in the scan, inside the
 * project, with a list that is present and closed; otherwise it is null and
 * the caller stays silent. A PHP interface declares no field, so an
 * `implements` edge is not walked, and an interface outside the scan
 * (`JsonSerializable`) costs nothing. A Python model's root outside the
 * project (`pydantic.BaseModel`, `TypedDict`) declares no field either, so
 * reaching it ends the walk, unless the scan holds a class of that name. A
 * TypeScript worker merges what its type checker resolves, a base in
 * `node_modules` included, and marks the rest open, and a Rust struct
 * inherits no field (its `implements` edges name traits), so their lists are
 * taken as sent.
 */
final class FieldLists
{
    /** The Python bases outside the project whose subclasses' annotations are fields, and which declare none. */
    private const PYTHON_ROOTS = ['pydantic.BaseModel', 'pydantic.main.BaseModel', 'typing.TypedDict', 'typing_extensions.TypedDict'];

    /** A Python reference to a class outside the project, the name captured. */
    private const PYTHON_REFERENCE = '/^py:(?:external_symbol|class):(.+)$/sD';

    private function __construct() {}

    /** The fields `$type` declares or inherits, nearest declaration first; null when that cannot be known. */
    public static function of(TestFidelityGraph $graph, NodeFact $type): ?FieldList
    {
        $own = FieldList::ofNode($type);
        if ($own === null || str_starts_with($type->localId, 'ts:') || str_starts_with($type->localId, 'rust:')) {
            return $own;
        }
        [$lineage, $unseen] = $graph->lineageThrough($type, static fn(string $parent): bool => !str_starts_with($parent, 'php:interface:') && !self::isPythonRoot($graph, $parent));
        if ($unseen) {
            return null;
        }
        $fields = [];
        foreach ($lineage as $ancestor) {
            $list = FieldList::ofNode($ancestor);
            if ($list === null) {
                return null;
            }
            $fields += $list->fields();
        }
        ksort($fields, SORT_STRING);

        return new FieldList($fields);
    }

    /** Whether a parent reference names a Python root the scan does not hold. */
    private static function isPythonRoot(TestFidelityGraph $graph, string $parent): bool
    {
        return !$graph->has($parent) && preg_match(self::PYTHON_REFERENCE, $parent, $match) === 1 && in_array($match[1], self::PYTHON_ROOTS, true);
    }
}
