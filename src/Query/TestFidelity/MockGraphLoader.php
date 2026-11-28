<?php

declare(strict_types=1);

namespace Knossos\Query\TestFidelity;

use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\TestFidelity\TestFidelityGraph;
use PDO;

/**
 * One type's lineage from the active graph, as a {@see TestFidelityGraph}:
 * the type, every type it reaches through `extends`, `implements` and
 * `uses_trait`, and what each contains, read under the references the
 * workers emitted ({@see StoredNodeFacts}). An ancestor the scan left
 * unresolved stays out, so the graph reports it unseen.
 */
final readonly class MockGraphLoader
{
    /** How many types one lineage holds at most; past them it says it was cut. */
    private const MAX_TYPES = 64;

    private const KINDS = "('contains', 'extends', 'implements', 'uses_trait')";

    public function __construct(private PDO $pdo) {}

    /**
     * The lineage of the node `$nodeId`, that node as the graph knows it,
     * and whether the walk stopped at its bound; null when the node is not
     * one the scan resolved.
     *
     * @return array{graph: TestFidelityGraph, node: NodeFact, cut: bool}|null
     */
    public function load(string $projectId, string $nodeId): ?array
    {
        $types = [$nodeId => true];
        $members = [];
        $edges = [];
        $cut = false;
        for ($frontier = [$nodeId]; $frontier !== []; $frontier = $next) {
            $next = [];
            $statement = $this->pdo->prepare(
                'SELECT e.kind, e.target_id, e.start_line, s.attributes_json AS source, t.attributes_json AS target, sf.relative_path '
                . 'FROM edges e JOIN nodes s ON s.id = e.source_id JOIN nodes t ON t.id = e.target_id LEFT JOIN files sf ON sf.id = s.file_id '
                . 'WHERE e.project_id = ? AND e.kind IN ' . self::KINDS . ' AND e.source_id IN (' . implode(',', array_fill(0, count($frontier), '?')) . ') ORDER BY e.id',
            );
            $statement->execute([$projectId, ...$frontier]);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $edges[] = $row;
                $target = (string) $row['target_id'];
                if ($row['kind'] === 'contains') {
                    $members[$target] = true;
                } elseif (!isset($types[$target])) {
                    if (count($types) >= self::MAX_TYPES) {
                        $cut = true;
                        continue;
                    }
                    $types[$target] = true;
                    $next[] = $target;
                }
            }
        }
        $ids = array_map(strval(...), array_keys($types + $members));
        $statement = $this->pdo->prepare(
            'SELECT n.id, n.kind, n.canonical_name, n.display_name, n.start_line, n.end_line, n.attributes_json, f.relative_path '
            . 'FROM nodes n LEFT JOIN files f ON f.id = n.file_id WHERE n.project_id = ? AND n.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY n.id',
        );
        $statement->execute([$projectId, ...$ids]);
        $nodes = [];
        $target = null;
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $node = StoredNodeFacts::node($row);
            if ($node !== null) {
                $nodes[] = $node;
                $target = $row['id'] === $nodeId ? $node : $target;
            }
        }

        return $target === null ? null : [
            'graph' => TestFidelityGraph::fromContributions([new ScanContribution('knossos.synthesis:graph', $nodes, StoredNodeFacts::edges($edges))]),
            'node' => $target,
            'cut' => $cut,
        ];
    }

    /**
     * Every trait impl the Rust worker saw for the type `$canonical`, from
     * each file's module `trait_impls`, in file then source order: an impl
     * may be written in any file of the crate.
     *
     * @return list<array<string, mixed>>
     */
    public function traitImpls(string $projectId, string $canonical): array
    {
        $statement = $this->pdo->prepare(
            "SELECT n.attributes_json FROM nodes n LEFT JOIN files f ON f.id = n.file_id WHERE n.project_id = ? AND n.kind = 'module' "
            . "AND json_extract(n.attributes_json, '$.trait_impls') IS NOT NULL ORDER BY f.relative_path, n.id",
        );
        $statement->execute([$projectId]);
        $impls = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $json) {
            $attributes = json_decode((string) $json, true);
            foreach (is_array($attributes['trait_impls'] ?? null) ? $attributes['trait_impls'] : [] as $entry) {
                if (is_array($entry) && ($entry['type'] ?? null) === $canonical) {
                    $impls[] = $entry;
                }
            }
        }

        return $impls;
    }
}
