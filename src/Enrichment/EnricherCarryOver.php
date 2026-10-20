<?php

declare(strict_types=1);

namespace Knossos\Enrichment;

use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\EdgeFact;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use Knossos\Scanner\Protocol\ScanContribution;
use PDO;
use Throwable;

/**
 * An enricher's facts from the stored graph, rebuilt for a scan that does not run it.
 *
 * A background scan never starts an enricher, and a failed run produced
 * nothing usable; either way the enricher's last good facts are carried into
 * the scan so pruning keeps them. They are rebuilt as the enricher reported
 * them (its own ids, native ends by stable id) and validated again against
 * this scan's native nodes, so an edge whose native end is gone and a
 * `represents` link that no longer holds are dropped, each with a warning.
 * The enricher's scan id and production time stay those of the run that
 * produced the facts; staleness is read from them.
 */
final class EnricherCarryOver
{
    /** @param array<string, true> $discoveredPaths project-relative paths this scan discovered */
    public function __construct(
        private PDO $pdo,
        private array $discoveredPaths,
    ) {}

    /** The stored facts of `$enricher`, validated against the current native nodes. */
    public function previous(string $projectId, string $enricher, NativeNodeIndex $native): ValidatedEnrichment
    {
        $owner = 'enricher:' . $enricher;
        $stamp = null;
        $own = [];
        $nodes = [];
        foreach ($this->rows($this->nodeSql(), $projectId, $owner) as $row) {
            $attributes = self::attributes($row['attributes_json']);
            $stamp ??= self::stamp($attributes);
            $local = (string) ($attributes['scanner_local_id'] ?? $row['id']);
            $local = str_starts_with($local, 'ext:' . $enricher . '/') ? substr($local, strlen('ext:' . $enricher . '/')) : $local;
            $own[(string) $row['id']] = $local;
            $node = self::fact(static fn(Evidence $evidence): NodeFact => new NodeFact(
                $local,
                (string) $row['kind'],
                (string) $row['canonical_name'],
                (string) $row['display_name'],
                Origin::Enricher,
                Confidence::from((string) $row['confidence']),
                $evidence,
                $attributes,
            ), $row);
            if ($node !== null) {
                $nodes[] = $node;
            }
        }
        $edges = [];
        foreach ($this->rows($this->edgeSql(), $projectId, $owner) as $row) {
            $attributes = self::attributes($row['attributes_json']);
            $stamp ??= self::stamp($attributes);
            $edge = self::fact(static fn(Evidence $evidence): EdgeFact => new EdgeFact(
                (string) $row['kind'],
                $own[(string) $row['source_id']] ?? (string) $row['source_id'],
                $own[(string) $row['target_id']] ?? (string) $row['target_id'],
                Origin::Enricher,
                Confidence::from((string) $row['confidence']),
                $evidence,
                $attributes,
            ), $row);
            if ($edge !== null) {
                $edges[] = $edge;
            }
        }
        [$scanId, $producedAt] = $stamp ?? ['', ''];

        return (new EnrichmentValidator($native, $this->discoveredPaths))
            ->validate($enricher, $scanId, $producedAt, new ScanContribution($owner, $nodes, $edges));
    }

    /** The stored nodes of one owner, with the path of the file their evidence is in. */
    private function nodeSql(): string
    {
        return 'SELECT n.id, n.kind, n.canonical_name, n.display_name, n.confidence, n.attributes_json, n.start_line, n.end_line, f.relative_path
                FROM nodes n LEFT JOIN files f ON f.id = n.file_id
                WHERE n.project_id = ? AND n.owner_key = ? ORDER BY n.id';
    }

    /** The stored edges of one owner, with the path of the file their evidence is in. */
    private function edgeSql(): string
    {
        return 'SELECT e.kind, e.source_id, e.target_id, e.confidence, e.attributes_json, e.start_line, e.end_line, f.relative_path
                FROM edges e LEFT JOIN files f ON f.id = e.file_id
                WHERE e.project_id = ? AND e.owner_key = ? ORDER BY e.id';
    }

    /**
     * Run one of the queries above for a project and owner.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, string $projectId, string $owner): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute([$projectId, $owner]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Build a fact from a stored row, or null when the row has no usable evidence.
     *
     * Every row the validator accepted had evidence in a discovered file, so a
     * row without one is damage from outside; leaving it out is what pruning
     * would do to it anyway.
     *
     * @template T
     * @param \Closure(Evidence): T $build
     * @param array<string, mixed> $row
     * @return T|null
     */
    private static function fact(\Closure $build, array $row): mixed
    {
        if (!is_string($row['relative_path']) || $row['start_line'] === null) {
            return null;
        }
        try {
            return $build(new Evidence($row['relative_path'], (int) $row['start_line'], (int) ($row['end_line'] ?? $row['start_line'])));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * A stored attributes object, or an empty one when it does not decode.
     *
     * @return array<string, mixed>
     */
    private static function attributes(mixed $json): array
    {
        $decoded = json_decode((string) $json, true);

        return is_array($decoded) && !array_is_list($decoded) ? $decoded : [];
    }

    /**
     * The enricher scan id and production time a stored fact carries, or null when it carries none.
     *
     * @param array<string, mixed> $attributes
     * @return array{string, string}|null
     */
    private static function stamp(array $attributes): ?array
    {
        $scanId = $attributes['enricher_scan_id'] ?? null;
        $producedAt = $attributes['enricher_produced_at'] ?? null;

        return is_string($scanId) && is_string($producedAt) ? [$scanId, $producedAt] : null;
    }
}
