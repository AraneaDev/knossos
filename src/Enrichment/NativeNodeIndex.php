<?php

declare(strict_types=1);

namespace Knossos\Enrichment;

use Knossos\Reconciliation\GraphReconciler;
use Knossos\Scanner\Protocol\ScanContribution;

/**
 * The native nodes of a scan, addressed by the stable id reconcile will give them.
 *
 * Stable ids exist only inside reconcile, but an enricher must name native
 * nodes before that runs. This index computes them up front with the
 * reconciler's own identity function, so the id an enricher is sent is the id
 * the graph stores, and maps it back to the worker's local id that the
 * reconciler resolves edges through.
 */
final class NativeNodeIndex
{
    /**
     * @param array<string, array{local_id: string, kind: string, qualified_name: string, file: string, line: int}> $nodes by stable id, first declaration wins as in the reconciler
     * @param array<string, true> $edges native edges keyed by {@see edgeKey()}
     */
    private function __construct(private array $nodes, private array $edges) {}

    /**
     * Index the nodes and edges the native workers reported.
     *
     * @param list<ScanContribution> $native
     */
    public static function build(string $projectId, array $native): self
    {
        $nodes = [];
        $edges = [];
        foreach ($native as $contribution) {
            foreach ($contribution->nodes as $node) {
                $id = GraphReconciler::nodeIdentity($projectId, $node)[1];
                $nodes[$id] ??= [
                    'local_id' => $node->localId,
                    'kind' => $node->kind,
                    'qualified_name' => $node->canonicalName,
                    'file' => $node->evidence->relativePath,
                    'line' => $node->evidence->startLine,
                ];
            }
            foreach ($contribution->edges as $edge) {
                $edges[self::edgeKey($edge->sourceReference, $edge->targetReference, $edge->kind)] = true;
            }
        }

        return new self($nodes, $edges);
    }

    /** The worker's local id for a native node's stable id, or null when this scan has no such node. */
    public function localIdFor(string $stableId): ?string
    {
        return $this->nodes[$stableId]['local_id'] ?? null;
    }

    /** The kind of a native node, or null when this scan has no such node. */
    public function kindOf(string $stableId): ?string
    {
        return $this->nodes[$stableId]['kind'] ?? null;
    }

    /** Whether a native worker reported exactly this edge, by worker local ids. */
    public function hasNativeEdge(string $sourceLocalId, string $targetLocalId, string $kind): bool
    {
        return isset($this->edges[self::edgeKey($sourceLocalId, $targetLocalId, $kind)]);
    }

    /**
     * The native nodes of the given kinds, in the shape an enricher receives them.
     *
     * @param list<string> $kinds
     * @return list<array{id: string, kind: string, qualified_name: string, file: ?string, line: ?int}>
     */
    public function nodesOfKinds(array $kinds): array
    {
        $wanted = array_flip($kinds);
        $result = [];
        foreach ($this->nodes as $id => $node) {
            if (isset($wanted[$node['kind']])) {
                $result[] = [
                    'id' => $id,
                    'kind' => $node['kind'],
                    'qualified_name' => $node['qualified_name'],
                    'file' => $node['file'],
                    'line' => $node['line'],
                ];
            }
        }

        return $result;
    }

    /** One key per (source, target, kind), unambiguous because local ids hold no NUL byte. */
    private static function edgeKey(string $source, string $target, string $kind): string
    {
        return $source . "\0" . $target . "\0" . $kind;
    }
}
