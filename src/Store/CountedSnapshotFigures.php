<?php

declare(strict_types=1);

namespace Knossos\Store;

use PDO;

/**
 * What a trend point can say of a project's graph without reading its rows:
 * how many components, relationships, roles, boundaries and diagnostics it
 * holds, and its errors and warnings, each a `COUNT(*)`. For a snapshot past
 * the row cap a whole read is not made within; the figures that need the
 * graph itself (cycles, hub degree, unreferenced components) are not counted.
 */
final class CountedSnapshotFigures
{
    /**
     * The project's figures as its live tables hold them now.
     *
     * @return array{counts: array{components: int, relationships: int, roles: int, boundaries: int, diagnostics: int}, metrics: array{error_diagnostics: int, warning_diagnostics: int}}
     */
    public static function of(PDO $pdo, string $projectId): array
    {
        $count = static function (string $sql) use ($pdo, $projectId): int {
            $statement = $pdo->prepare($sql);
            $statement->execute(['project' => $projectId]);
            return (int) $statement->fetchColumn();
        };
        // A snapshot holds native facts only, so its counts leave enriched rows and enricher diagnostics out too.
        $rows = static fn(string $table): string => sprintf('SELECT COUNT(*) FROM %s WHERE project_id = :project', $table)
            . EnrichedFacts::nativeCondition($table);

        return [
            'counts' => [
                'components' => $count($rows('nodes')), 'relationships' => $count($rows('edges')), 'roles' => $count($rows('classifications')),
                'boundaries' => $count($rows('boundaries')), 'diagnostics' => $count($rows('diagnostics')),
            ],
            'metrics' => [
                'error_diagnostics' => $count($rows('diagnostics') . " AND severity = 'error'"),
                'warning_diagnostics' => $count($rows('diagnostics') . " AND severity = 'warning'"),
            ],
        ];
    }
}
