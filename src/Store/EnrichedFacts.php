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
}
