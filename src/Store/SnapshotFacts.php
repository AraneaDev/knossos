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
     * @param string $table an unaliased table name, which is also the alias the condition tests
     */
    public static function condition(string $table): string
    {
        $kinds = "'" . implode("', '", self::DERIVED_NODE_KINDS) . "'";
        $derived = static fn(string $nodeIds): string => sprintf(
            ' AND NOT EXISTS (SELECT 1 FROM nodes derived WHERE derived.id IN (%s) AND derived.kind IN (%s))',
            $nodeIds,
            $kinds,
        );

        return EnrichedFacts::nativeCondition($table) . match ($table) {
            'nodes' => " AND nodes.kind NOT IN ({$kinds})",
            'edges' => " AND edges.kind <> '" . self::DERIVED_EDGE_KIND . "'" . $derived('edges.source_id, edges.target_id'),
            'classifications', 'boundary_memberships' => $derived("{$table}.node_id"),
            default => '',
        };
    }
}
