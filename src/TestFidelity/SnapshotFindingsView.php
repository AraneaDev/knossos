<?php

declare(strict_types=1);

namespace Knossos\TestFidelity;

use PDO;

/**
 * The test findings of any snapshot of a project as a comparison reads them:
 * keyed, with the project's current suppressions applied, and null when the
 * snapshot has no record.
 *
 * An archived snapshot is read from what it was archived with; the active one
 * from the live findings ({@see ActiveTestFindings}), unless the test rules
 * never judged it. The suppressions are {@see TestFindingSuppressions}'s one
 * mechanism, applied to both kinds, so a diff and a trend count what the gate does.
 *
 * @phpstan-type Item array{key: string, category: string, sub_rule: string, confidence: string, file: ?string, message: string}
 */
final readonly class SnapshotFindingsView
{
    private SnapshotTestFindings $archive;

    public function __construct(
        private PDO $pdo,
        private string $projectId,
        private string $activeScanId,
        private TestFindingSuppressions $suppressions,
    ) {
        $this->archive = new SnapshotTestFindings($pdo);
    }

    /**
     * The findings of `$scanId` by key, in key order, or null when it has no record.
     *
     * @return array<string, Item>|null
     */
    public function keyed(string $scanId): ?array
    {
        $items = [];
        if ($scanId === $this->activeScanId) {
            if (!TurnTestFindings::judged($this->pdo, $this->projectId)) {
                return null;
            }
            foreach ((new ActiveTestFindings($this->pdo, $this->projectId, $this->suppressions))->find() as $finding) {
                $items[$finding['key']] = self::item($finding);
            }
        } else {
            $recorded = $this->archive->forSnapshot($this->projectId, $scanId);
            if ($recorded === null) {
                return null;
            }
            foreach ($recorded as $finding) {
                if (!$this->suppressions->suppresses($finding['rule_id'], $finding['category'], $finding['sub_rule'], (string) $finding['file'])) {
                    $items[$finding['key']] = self::item($finding);
                }
            }
        }
        ksort($items, SORT_STRING);

        return $items;
    }

    /**
     * How many definite findings the snapshot has, and how many of those are
     * stale mocks; null for both when it has no record, which is not none.
     *
     * @return array{definite_test_findings: ?int, stale_test_findings: ?int}
     */
    public function counts(string $scanId): array
    {
        $items = $this->keyed($scanId);
        if ($items === null) {
            return ['definite_test_findings' => null, 'stale_test_findings' => null];
        }
        $definite = array_filter($items, static fn(array $item): bool => $item['confidence'] === FindingConfidence::Definite->value);

        return [
            'definite_test_findings' => count($definite),
            'stale_test_findings' => count(array_filter($definite, static fn(array $item): bool => StaleCategories::isStale($item['category']))),
        ];
    }

    /**
     * @param array<string, mixed> $finding
     * @return Item
     */
    private static function item(array $finding): array
    {
        return ['key' => $finding['key'], 'category' => $finding['category'], 'sub_rule' => $finding['sub_rule'],
            'confidence' => $finding['confidence'], 'file' => $finding['file'], 'message' => $finding['message']];
    }
}
