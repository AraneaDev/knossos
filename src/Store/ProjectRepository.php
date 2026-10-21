<?php

declare(strict_types=1);

namespace Knossos\Store;

use InvalidArgumentException;
use PDO;

/**
 * Reads and removes rows of the `projects` table by id or by root.
 *
 * Each lookup selects only the columns its caller names, so a row handed on
 * as part of an answer carries exactly the fields it did when the caller
 * wrote the query itself.
 */
final readonly class ProjectRepository
{
    /** @param PDO $pdo an existing, migrated graph database */
    public function __construct(private PDO $pdo) {}

    /**
     * The project row with this id, or null when there is none.
     *
     * @param list<string> $columns the columns to select, `*` for all
     * @return array<string, mixed>|null
     */
    public function find(string $id, array $columns = ['*']): ?array
    {
        return $this->one('id', $id, $columns);
    }

    /**
     * The project row scanned from this root, or null when there is none.
     *
     * @param list<string> $columns the columns to select, `*` for all
     * @return array<string, mixed>|null
     */
    public function byRoot(string $root, array $columns = ['*']): ?array
    {
        return $this->one('root_realpath', $root, $columns);
    }

    /** Whether a project row with this id exists. */
    public function exists(string $id): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM projects WHERE id = :id');
        $statement->execute(['id' => $id]);

        return $statement->fetchColumn() !== false;
    }

    /** The project's root on disk, or null when the project is unknown or has none. */
    public function rootOf(string $id): ?string
    {
        return $this->nonEmpty($id, 'root_realpath');
    }

    /** The project's active snapshot id, or null when it has none. */
    public function activeScanOf(string $id): ?string
    {
        return $this->nonEmpty($id, 'active_scan_id');
    }

    /** The project's stored configuration JSON, or null when the project is unknown or has none. */
    public function configOf(string $id): ?string
    {
        $raw = $this->column($id, 'config_json');

        return is_string($raw) ? $raw : null;
    }

    /**
     * Delete a project row; the cascade takes the graph, snapshots and scan
     * history with it. The active scan is cleared first, as its foreign key
     * points back at the project's own scans. The caller owns the transaction.
     */
    public function remove(string $id): void
    {
        $this->pdo->prepare('UPDATE projects SET active_scan_id = NULL WHERE id = :project')->execute(['project' => $id]);
        $this->pdo->prepare('DELETE FROM projects WHERE id = :project')->execute(['project' => $id]);
    }

    /**
     * One row matched on one key column.
     *
     * @param 'id'|'root_realpath' $key
     * @param list<string> $columns
     * @return array<string, mixed>|null
     */
    private function one(string $key, string $value, array $columns): ?array
    {
        foreach ($columns as $column) {
            if ($column !== '*' && preg_match('/^[a-z_]+$/', $column) !== 1) {
                throw new InvalidArgumentException(sprintf('Not a projects column: %s', $column));
            }
        }
        $statement = $this->pdo->prepare(sprintf('SELECT %s FROM projects WHERE %s = :value', implode(', ', $columns), $key));
        $statement->execute(['value' => $value]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** One column of the project row, false when there is no such project. */
    private function column(string $id, string $column): mixed
    {
        $statement = $this->pdo->prepare(sprintf('SELECT %s FROM projects WHERE id = :id', $column));
        $statement->execute(['id' => $id]);

        return $statement->fetchColumn();
    }

    /** A string column of the project row, null when absent or empty. */
    private function nonEmpty(string $id, string $column): ?string
    {
        $value = $this->column($id, $column);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
