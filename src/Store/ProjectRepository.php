<?php

declare(strict_types=1);

namespace Knossos\Store;

use InvalidArgumentException;
use PDO;

/**
 * Reads and removes rows of the `projects` table by id, by root, or by a path inside a root.
 *
 * Each lookup selects only the columns its caller names, so a row handed on
 * as part of an answer carries exactly the fields it did when the caller
 * wrote the query itself.
 */
final readonly class ProjectRepository
{
    /** Guards against a pathological path burning the hook's time budget on ancestors. */
    private const MAX_ANCESTORS = 64;

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

    /**
     * The project whose root contains this path, or null when no scanned project contains it.
     *
     * Path-addressed rather than id-addressed because the caller that matters
     * is a session-start hook, which knows a working directory and nothing
     * else. The parent walk exists because a session can start in any
     * subdirectory of a repository, and an exact-match-only lookup would
     * report those as unscanned.
     *
     * @return array<string, mixed>|null the row's id, name, root_realpath and active_scan_id
     */
    public function resolvePath(string $path): ?array
    {
        // Normalise when the path exists, fall back to it as given when it does not.
        // Bailing out on a false realpath would report an existing-but-unreadable path
        // as unscanned, and would fail against stored roots that are not present on
        // this filesystem.
        // Falling back to the path AS GIVEN keeps an existing-but-unreadable
        // path resolvable, and matches stored roots absent from this
        // filesystem. A relative fallback has to be made absolute all the
        // same: the ancestor walk below would otherwise climb to "." and stop,
        // never meeting a project root, which is always absolute.
        $current = realpath($path) ?: self::absolute($path);
        for ($depth = 0; $depth < self::MAX_ANCESTORS; $depth++) {
            $row = $this->byRoot($current, ['id', 'name', 'root_realpath', 'active_scan_id']);
            if ($row !== null) {
                return $row;
            }
            $parent = dirname($current);
            if ($parent === $current) {
                return null;
            }
            $current = $parent;
        }
        return null;
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
     * A path as given when it is already absolute, resolved against the working
     * directory when it is not.
     *
     * Lexical, not filesystem-backed: this runs precisely when the path does
     * not exist, so there is nothing to canonicalise.
     */
    private static function absolute(string $path): string
    {
        if ($path === '' || $path[0] === '/' || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1) {
            return $path;
        }
        $cwd = getcwd();

        return $cwd === false ? $path : rtrim($cwd, '/') . '/' . $path;
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
