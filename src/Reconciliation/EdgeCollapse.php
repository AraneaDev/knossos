<?php

declare(strict_types=1);

namespace Knossos\Reconciliation;

use Knossos\Scanner\Protocol\ConfidenceRank;
use Knossos\Scanner\Protocol\EdgeFact;
use Knossos\Scanner\Protocol\ErasedTypeEdge;
use Knossos\Store\StableId;

/**
 * Collapses the resolved edges of a scan into one edge per kind, source,
 * target and owner (and per `prefix` for `mounts`, per `access` for an edge that
 * reads or writes a property), the rule workers rely on when they report one
 * fact per site. The access is part of such an edge's stable id too.
 *
 * The surest occurrence wins: the higher confidence, then a non-speculative
 * occurrence over a speculative one, then the earliest evidence. Attributes are
 * the union of every occurrence, the winner's value standing on a conflicting
 * key; `speculative` is the winner's alone, since a confirmed site confirms the
 * edge. For an {@see ErasedTypeEdge} kind, when any occurrence carries
 * `type_only`, `type_only_variants` lists every value seen (a missing marker is
 * `false`), so one runtime import keeps the dependency real. The edge's
 * evidence is the winner's span (path, start line, end line), so the line
 * points at the site that earned the confidence, and its stable id hashes that
 * evidence.
 */
final class EdgeCollapse
{
    /** @var array<string, list<array{edge: EdgeFact, source: string, target: string, owner: string}>> */
    private array $groups = [];

    /** @param array<string, string> $fileIds relative path => file id */
    public function __construct(
        private readonly string $projectId,
        private readonly array $fileIds,
    ) {}

    /** Record one resolved occurrence of an edge. */
    public function add(EdgeFact $edge, string $sourceId, string $targetId, string $owner): void
    {
        // A file component's edges point back into a file no worker scans, which has no file row.
        if (!isset($this->fileIds[$edge->evidence->relativePath]) && $owner !== NamedFiles::OWNER) {
            throw new ReconciliationException(sprintf(
                'Edge evidence file was not discovered: %s',
                $edge->evidence->relativePath,
            ));
        }
        $prefix = $edge->kind === 'mounts' ? json_encode($edge->attributes['prefix'] ?? null, JSON_THROW_ON_ERROR) : '';
        $key = implode("\0", [$edge->kind, $sourceId, $targetId, $owner, $prefix, self::access($edge)]);
        $this->groups[$key][] = ['edge' => $edge, 'source' => $sourceId, 'target' => $targetId, 'owner' => $owner];
    }

    /**
     * One record per collapsed edge, keyed by its stable id.
     *
     * @return array<string, array<string, mixed>>
     */
    public function records(): array
    {
        $records = [];
        foreach ($this->groups as $occurrences) {
            $record = $this->collapse($occurrences);
            $records[$record['id']] = $record;
        }

        return $records;
    }

    /**
     * The one record a group of occurrences becomes.
     *
     * @param non-empty-list<array{edge: EdgeFact, source: string, target: string, owner: string}> $occurrences
     * @return array<string, mixed>
     */
    private function collapse(array $occurrences): array
    {
        $winner = $occurrences[0];
        foreach ($occurrences as $occurrence) {
            if (self::compareStrength($occurrence['edge'], $winner['edge']) > 0) {
                $winner = $occurrence;
            }
        }
        $edge = $winner['edge'];
        $evidence = $edge->evidence;
        $evidenceKey = sprintf('%s:%d:%d:%s', $evidence->relativePath, $evidence->startLine, $evidence->endLine, $winner['owner']);
        // A read and a write of one property on one line are two edges with the same span: the access
        // keeps their ids apart. Without one, the key, and so every other edge's id, is as it was.
        $access = self::access($edge);
        if ($access !== '') {
            $evidenceKey .= ':' . $access;
        }
        $id = StableId::edge($this->projectId, $edge->kind, $winner['source'], $winner['target'], $evidenceKey);

        return [
            'id' => $id,
            'kind' => $edge->kind,
            'source_id' => $winner['source'],
            'target_id' => $winner['target'],
            'file_id' => $this->fileIds[$evidence->relativePath] ?? null,
            'start_line' => $evidence->startLine,
            'end_line' => $evidence->endLine,
            'origin' => $edge->origin->value,
            'confidence' => $edge->confidence->value,
            'attributes' => self::attributes($edge, array_map(static fn(array $occurrence): EdgeFact => $occurrence['edge'], $occurrences)),
            'owner_key' => $winner['owner'],
        ];
    }

    /**
     * The union of every occurrence's attributes, the winner's value standing on a conflict.
     *
     * @param non-empty-list<EdgeFact> $edges
     * @return array<string, mixed>
     */
    private static function attributes(EdgeFact $winner, array $edges): array
    {
        $attributes = $winner->attributes;
        $typed = false;
        $variants = [];
        foreach ($edges as $edge) {
            $attributes += $edge->attributes;
            $typed = $typed || array_key_exists('type_only', $edge->attributes);
            $variants[] = ($edge->attributes['type_only'] ?? false) === true;
            foreach (is_array($edge->attributes['type_only_variants'] ?? null) ? $edge->attributes['type_only_variants'] : [] as $variant) {
                $variants[] = $variant === true;
            }
        }
        if (array_key_exists('speculative', $winner->attributes)) {
            $attributes['speculative'] = $winner->attributes['speculative'];
        } else {
            unset($attributes['speculative']);
        }
        if (count($edges) > 1 && $typed && in_array($winner->kind, ErasedTypeEdge::KINDS, true)) {
            $variants = array_values(array_unique($variants));
            sort($variants);
            $attributes['type_only_variants'] = $variants;
        }

        return $attributes;
    }

    /** Positive when `$a` is the surer occurrence: confidence, then not speculative, then earlier evidence. */
    private static function compareStrength(EdgeFact $a, EdgeFact $b): int
    {
        return [ConfidenceRank::NAMES[$a->confidence->value], !self::speculative($a)]
            <=> [ConfidenceRank::NAMES[$b->confidence->value], !self::speculative($b)]
            ?: self::compareEvidence($b, $a);
    }

    /** Orders two occurrences by their evidence: path, start line, end line. */
    private static function compareEvidence(EdgeFact $a, EdgeFact $b): int
    {
        return strcmp($a->evidence->relativePath, $b->evidence->relativePath)
            ?: [$a->evidence->startLine, $a->evidence->endLine] <=> [$b->evidence->startLine, $b->evidence->endLine];
    }

    /** How the edge accesses a property (`read`, `write`, `read_write`), empty for any other edge. */
    private static function access(EdgeFact $edge): string
    {
        return is_string($edge->attributes['access'] ?? null) ? $edge->attributes['access'] : '';
    }

    /** Whether a worker marked the occurrence speculative. */
    private static function speculative(EdgeFact $edge): bool
    {
        return ($edge->attributes['speculative'] ?? false) === true;
    }
}
