<?php

declare(strict_types=1);

namespace Knossos\Store;

/**
 * The rows an external enricher contributed, and the condition that leaves them out.
 *
 * Enriched nodes and edges are additive, labelled guesses about a project. A
 * metric, a health or summary count, a dead-code check, a snapshot and
 * everything compared against a snapshot (diffs, gates, trends) describe
 * native code only, so an enricher whose output changes with no code change
 * never shows up as architecture drift or moves a budget. Each of those reads
 * adds this one condition, so they cannot disagree about what is enriched.
 */
final class EnrichedFacts
{
    /** The `origin` every enriched node and edge carries, whatever the enricher declared. */
    public const ORIGIN = 'enricher';

    /** The tables whose rows carry an `origin`, and so can be enriched. */
    public const TABLES = ['nodes', 'edges'];

    /** The owner key prefix of everything an enricher contributes, its diagnostics included. */
    public const OWNER_PREFIX = 'enricher:';

    private function __construct() {}

    /**
     * An SQL condition holding for a native row of `nodes` or `edges`.
     *
     * @param string $alias the table alias (or bare table name) whose `origin` column is tested
     */
    public static function excludeSql(string $alias): string
    {
        return "{$alias}.origin <> '" . self::ORIGIN . "'";
    }

    /**
     * The condition, joined with a leading ` AND `, that leaves out of `$table`
     * what enrichers contributed: enriched nodes and edges, and the diagnostics
     * an enricher owns (its own reports and those about its runs). Empty for a
     * table enrichers never write.
     *
     * A diagnostic about an enricher still shows in `list_diagnostics`; it is
     * left out of the counts that describe the project, so a failing enricher
     * never moves a diagnostics budget.
     *
     * @param string $table an unaliased table name, which is also the alias the condition tests
     */
    public static function nativeCondition(string $table): string
    {
        if ($table === 'diagnostics') {
            return " AND diagnostics.owner_key NOT LIKE '" . self::OWNER_PREFIX . "%'";
        }

        return in_array($table, self::TABLES, true) ? ' AND ' . self::excludeSql($table) : '';
    }
}
