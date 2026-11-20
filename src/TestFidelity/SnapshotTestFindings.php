<?php

declare(strict_types=1);

namespace Knossos\TestFidelity;

use PDO;

/**
 * The test findings a snapshot was archived with, keyed by {@see TestFindingKey}.
 *
 * All of them, suppressed or not: a suppression is applied by whoever reads,
 * so an edit to `knossos.json` reaches a baseline as well.
 */
final readonly class SnapshotTestFindings
{
    public function __construct(private PDO $pdo) {}

    /**
     * The findings of a snapshot, sorted by key, or null when it has no record
     * (archived before they were kept, or no such snapshot), which is not the
     * same as an empty list: a snapshot that was recorded and had none.
     *
     * @return list<array{key: string, rule_id: string, category: string, sub_rule: string, confidence: string, file: ?string, target: ?string, message: string}>|null
     */
    public function forSnapshot(string $projectId, string $scanId): ?array
    {
        $recorded = $this->pdo->prepare('SELECT test_findings_recorded FROM scan_snapshots WHERE scan_id = :scan AND project_id = :project');
        $recorded->execute(['scan' => $scanId, 'project' => $projectId]);
        if ((int) $recorded->fetchColumn() !== 1) {
            return null;
        }
        $statement = $this->pdo->prepare('SELECT key, rule_id, category, sub_rule, confidence, file, target, message FROM snapshot_test_findings WHERE scan_id = :scan ORDER BY key');
        $statement->execute(['scan' => $scanId]);

        return array_map(static fn(array $row): array => [
            'key' => (string) $row['key'],
            'rule_id' => (string) $row['rule_id'],
            'category' => (string) $row['category'],
            'sub_rule' => (string) $row['sub_rule'],
            'confidence' => (string) $row['confidence'],
            'file' => $row['file'] === null ? null : (string) $row['file'],
            'target' => $row['target'] === null ? null : (string) $row['target'],
            'message' => (string) $row['message'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }
}
