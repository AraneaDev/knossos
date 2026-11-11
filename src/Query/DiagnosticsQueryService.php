<?php

declare(strict_types=1);

namespace Knossos\Query;

use InvalidArgumentException;
use Knossos\Result\ResultEnvelope;
use Knossos\Store\ProjectRepository;
use PDO;

/** The active graph's scan diagnostics: by file and line, filtered by severity and path. */
final readonly class DiagnosticsQueryService extends AbstractArchitectureQueryService
{
    private const SEVERITIES = ['error', 'warning', 'info'];

    /** A page of the project's diagnostics, ordered by file and line, file-less ones last. */
    public function listDiagnostics(string $projectId, ?string $severity = null, ?string $pathPrefix = null, int $limit = 100, int $offset = 0): ResultEnvelope
    {
        $project = $this->project($projectId);
        self::assertLimit($limit);
        if ($severity !== null && !in_array($severity, self::SEVERITIES, true)) {
            throw new InvalidArgumentException('severity must be one of: ' . implode(', ', self::SEVERITIES) . '.');
        }
        if ($offset < 0 || $offset > 100_000) {
            throw new InvalidArgumentException('offset must be between 0 and 100000.');
        }
        $where = 'd.project_id = :project';
        $parameters = ['project' => $projectId];
        if ($severity !== null) {
            $where .= ' AND d.severity = :severity';
            $parameters['severity'] = $severity;
        }
        if ($pathPrefix !== null && $pathPrefix !== '') {
            // Compared as bytes: LIKE would fold ASCII case and read `_` and `%` as wildcards.
            $where .= ' AND substr(f.relative_path, 1, length(:prefix)) = :prefix';
            $parameters['prefix'] = $pathPrefix;
        }
        // What the project's `diagnostic_suppressions` mark as not worth
        // reporting is left out, and counted.
        [$suppressed, $suppressionParameters] = $this->suppressionCondition($projectId);
        $suppressedCount = 0;
        if ($suppressed !== null) {
            $hidden = $this->pdo->prepare("SELECT COUNT(*) FROM diagnostics d LEFT JOIN files f ON f.id = d.file_id WHERE {$where} AND ({$suppressed})");
            $hidden->execute($parameters + $suppressionParameters);
            $suppressedCount = (int) $hidden->fetchColumn();
            $where .= " AND NOT ({$suppressed})";
            $parameters += $suppressionParameters;
        }
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM diagnostics d LEFT JOIN files f ON f.id = d.file_id WHERE {$where}");
        $count->execute($parameters);
        $total = (int) $count->fetchColumn();
        $statement = $this->pdo->prepare(
            "SELECT d.severity, d.code, d.message, f.relative_path, d.start_line, d.owner_key FROM diagnostics d LEFT JOIN files f ON f.id = d.file_id WHERE {$where} "
            . 'ORDER BY f.relative_path IS NULL, f.relative_path, d.start_line, d.code, d.id LIMIT :limit OFFSET :offset',
        );
        foreach ($parameters as $key => $value) {
            $statement->bindValue(':' . $key, $value);
        }
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();
        $rows = array_map(static fn(array $row): array => [
            'severity' => (string) $row['severity'],
            'code' => (string) $row['code'],
            'message' => (string) $row['message'],
            'path' => $row['relative_path'] === null ? null : (string) $row['relative_path'],
            'line' => $row['start_line'] === null ? null : (int) $row['start_line'],
            'source' => DiagnosticSource::of((string) $row['owner_key']),
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
        $next = $offset + count($rows) < $total ? $offset + count($rows) : null;

        return new ResultEnvelope(
            $projectId,
            $project['active_scan_id'],
            sprintf('%d diagnostic%s.', $total, $total === 1 ? '' : 's')
                . ($suppressedCount > 0 ? sprintf(' %d more the project suppresses.', $suppressedCount) : ''),
            ['diagnostics' => $rows, 'total' => $total, 'suppressed' => $suppressedCount, 'pagination' => ['offset' => $offset, 'next_offset' => $next]],
            [],
            [],
            $next !== null,
        );
    }

    /**
     * The SQL condition a diagnostic the project's `diagnostic_suppressions`
     * cover meets, with its parameters, or null when it suppresses none. A
     * suppression names a code and optionally a file, exactly or by a prefix
     * with a trailing `*`.
     *
     * @return array{0: ?string, 1: array<string, string>}
     */
    private function suppressionCondition(string $projectId): array
    {
        $config = (new ProjectRepository($this->pdo))->config($projectId);
        $list = is_array($config['diagnostic_suppressions'] ?? null) ? $config['diagnostic_suppressions'] : [];
        $conditions = [];
        $parameters = [];
        foreach (array_values($list) as $index => $suppression) {
            if (!is_array($suppression) || !is_string($suppression['code'] ?? null)) {
                continue;
            }
            $parameters["suppressed_code_{$index}"] = $suppression['code'];
            $condition = "d.code = :suppressed_code_{$index}";
            $path = $suppression['path'] ?? null;
            if (is_string($path) && $path !== '') {
                if (str_ends_with($path, '*')) {
                    $parameters["suppressed_path_{$index}"] = substr($path, 0, -1);
                    $condition .= " AND substr(f.relative_path, 1, length(:suppressed_path_{$index})) = :suppressed_path_{$index}";
                } else {
                    $parameters["suppressed_path_{$index}"] = $path;
                    $condition .= " AND f.relative_path = :suppressed_path_{$index}";
                }
            }
            $conditions[] = "({$condition})";
        }

        return [$conditions === [] ? null : implode(' OR ', $conditions), $parameters];
    }
}
