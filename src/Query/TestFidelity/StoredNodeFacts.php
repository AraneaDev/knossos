<?php

declare(strict_types=1);

namespace Knossos\Query\TestFidelity;

use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\EdgeFact;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;

/**
 * Stored nodes and edges read back under the references the workers
 * emitted (`scanner_local_id`), so a {@see \Knossos\TestFidelity\TestFidelityGraph}
 * built from them answers as the scan's own does. A node the scan left
 * unresolved is no node here: an edge to it names an ancestor the scan does
 * not hold.
 */
final class StoredNodeFacts
{
    private function __construct() {}

    /**
     * A stored node as the worker emitted it; null for one the scan left unresolved.
     *
     * @param array<string, mixed> $row `kind`, `canonical_name`, `display_name`, `start_line`, `end_line`, `attributes_json` and `relative_path`
     */
    public static function node(array $row): ?NodeFact
    {
        $attributes = json_decode((string) $row['attributes_json'], true);
        $attributes = is_array($attributes) ? $attributes : [];
        $reference = $attributes['scanner_local_id'] ?? null;
        if (!is_string($reference) || $reference === '' || ($attributes['unresolved'] ?? false) === true) {
            return null;
        }
        $start = max(1, (int) ($row['start_line'] ?? 1));

        return new NodeFact($reference, (string) $row['kind'], (string) $row['canonical_name'], (string) $row['display_name'], Origin::Ast, Confidence::Certain, new Evidence((string) $row['relative_path'], $start, max($start, (int) ($row['end_line'] ?? $start))), $attributes);
    }

    /**
     * Stored edges under the references the worker emitted: a resolved
     * node's `scanner_local_id`, or the reference an unresolved one keeps.
     *
     * @param list<array<string, mixed>> $rows each `kind`, `source` and `target` (the ends' attribute JSON), `relative_path` and `start_line`
     * @return list<EdgeFact>
     */
    public static function edges(array $rows): array
    {
        $edges = [];
        foreach ($rows as $row) {
            $source = self::reference((string) $row['source']);
            $target = self::reference((string) $row['target']);
            if ($source !== null && $target !== null) {
                $line = max(1, (int) ($row['start_line'] ?? 1));
                $edges[] = new EdgeFact((string) $row['kind'], $source, $target, Origin::Ast, Confidence::Certain, new Evidence((string) $row['relative_path'], $line, $line));
            }
        }

        return $edges;
    }

    /** The reference a stored node is known by to the worker that emitted or named it. */
    private static function reference(string $attributesJson): ?string
    {
        $attributes = json_decode($attributesJson, true);
        $reference = is_array($attributes) ? ($attributes['scanner_local_id'] ?? $attributes['reference'] ?? null) : null;

        return is_string($reference) && $reference !== '' ? $reference : null;
    }
}
