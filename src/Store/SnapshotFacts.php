<?php

declare(strict_types=1);

namespace Knossos\Store;

/**
 * The rows a snapshot records, and the condition that leaves the rest out.
 *
 * A snapshot holds native facts ({@see EnrichedFacts}) and, of those, only
 * the ones that describe the architecture. Test doubles and test cases are
 * derived per scan, as findings are: their identities carry the line they
 * sit on, so one comment added at the top of a test file would otherwise
 * read as every one of them removed and added again. They stay out of a
 * snapshot, and so do the edges, roles and boundary memberships that touch
 * them; everything compared against a snapshot (a diff, a gate, a trend)
 * reads the active graph through the same condition.
 *
 * The one exception is a read of edges restricted to impact edge kinds (the
 * health and hub-ranking degree walks): a derived test fact has no edge of
 * those kinds (its edges are `contains` and `doubles`), and the per-edge
 * test the condition adds would take those reads off their covering indexes.
 */
final class SnapshotFacts
{
    /** The node kinds a scan derives from test code. */
    public const DERIVED_NODE_KINDS = ['test_double', 'test_case'];

    /** The edge kind that only ever leaves a test double. */
    public const DERIVED_EDGE_KIND = 'doubles';

    private function __construct() {}

    /**
     * The condition, joined with a leading ` AND `, that leaves out of
     * `$table` what a snapshot does not record: enriched rows, derived test
     * facts and the rows that touch a derived test fact.
     *
     * @param string $table the table name, which is also the alias the condition tests unless `$alias` says otherwise
     * @param string|null $alias the alias the table is read under
     */
    public static function condition(string $table, ?string $alias = null): string
    {
        $native = $alias === null || !in_array($table, EnrichedFacts::TABLES, true)
            ? EnrichedFacts::nativeCondition($table, $alias)
            : ' AND ' . EnrichedFacts::excludeSql($alias);

        return $native . self::derivedCondition($table, $alias ?? $table);
    }

    /**
     * The same condition for a `nodes` or `edges` row read under `$alias`, with no leading
     * ` AND `: a native row that is not, and does not touch, a derived test fact. For the
     * counts and graph walks that describe the project's own code.
     *
     * @param 'nodes'|'edges' $table
     */
    public static function nativeSql(string $table, string $alias): string
    {
        return EnrichedFacts::excludeSql($alias) . self::derivedCondition($table, $alias);
    }

    /** What leaves derived test facts out of `$table` read as `$alias`, with a leading ` AND `. */
    private static function derivedCondition(string $table, string $alias): string
    {
        $kinds = "'" . implode("', '", self::DERIVED_NODE_KINDS) . "'";
        $derived = static fn(string $nodeIds): string => sprintf(
            ' AND NOT EXISTS (SELECT 1 FROM nodes derived WHERE derived.id IN (%s) AND derived.kind IN (%s))',
            $nodeIds,
            $kinds,
        );

        return match ($table) {
            'nodes' => " AND {$alias}.kind NOT IN ({$kinds})",
            'edges' => " AND {$alias}.kind <> '" . self::DERIVED_EDGE_KIND . "'" . $derived("{$alias}.source_id, {$alias}.target_id"),
            'classifications', 'boundary_memberships' => $derived("{$alias}.node_id"),
            default => '',
        };
    }
}
