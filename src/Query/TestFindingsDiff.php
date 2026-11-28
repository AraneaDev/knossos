<?php

declare(strict_types=1);

namespace Knossos\Query;

use Knossos\TestFidelity\{ActiveTestFindings, SnapshotFindingsView};
use PDO;

/**
 * The `test_findings` section of `snapshot_diff`: the test findings one
 * snapshot has that the other does not, compared by key so a finding that only
 * moved lines is neither added nor removed.
 *
 * A side with no record cannot be compared, and says which one, rather than
 * reporting every finding of the other as added or removed.
 */
final class TestFindingsDiff
{
    private function __construct() {}

    /**
     * {@see self::between()} of a project, read with the suppressions of its `knossos.json`.
     *
     * @param array<string, mixed> $project the project row
     * @return array{section: array<string, mixed>, truncated: bool}
     */
    public static function of(PDO $pdo, array $project, string $fromScan, string $toScan, int $limit): array
    {
        $suppressions = ActiveTestFindings::suppressionsOf((string) $project['root_realpath']);

        return self::between(new SnapshotFindingsView($pdo, (string) $project['id'], (string) $project['active_scan_id'], $suppressions), $fromScan, $toScan, $limit);
    }

    /**
     * The section, and whether it left changes out for `$limit`.
     *
     * @return array{section: array<string, mixed>, truncated: bool}
     */
    public static function between(SnapshotFindingsView $view, string $fromScan, string $toScan, int $limit): array
    {
        $before = $view->keyed($fromScan);
        $after = $view->keyed($toScan);
        foreach (['from' => $before, 'to' => $after] as $side => $items) {
            if ($items === null) {
                return ['section' => ['status' => 'not_recorded', 'side' => $side], 'truncated' => false];
            }
        }
        $added = array_values(array_diff_key($after ?? [], $before ?? []));
        $removed = array_values(array_diff_key($before ?? [], $after ?? []));
        // One budget, spent on the additions first, as the other sections spend theirs.
        $keptAdded = array_slice($added, 0, $limit);
        $keptRemoved = array_slice($removed, 0, $limit - count($keptAdded));
        $truncated = count($keptAdded) < count($added) || count($keptRemoved) < count($removed);

        return [
            'section' => [
                'counts' => ['added' => count($added), 'removed' => count($removed)],
                'added' => $keptAdded,
                'removed' => $keptRemoved,
                'truncated' => $truncated,
            ],
            'truncated' => $truncated,
        ];
    }
}
