<?php

declare(strict_types=1);

namespace Knossos\Store;

use InvalidArgumentException;
use PDO;

/**
 * Reads and removes rows of the `projects` table by id, by root, or by a path inside a root.
 *
 * Every query on `projects` goes through here, including the few that join a
 * project to its scans, so the table has one reader to change.
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
     * The project's stored configuration, decoded, or null when the project is
     * unknown or its configuration does not decode to an object.
     *
     * Lenient on purpose: the store wrote this JSON itself, so one that does
     * not decode is damage for `doctor` to report, not a reason to fail the
     * caller. Every caller has a safe answer without it: the default snapshot
     * retention, or a full analysis instead of the no-change fast path.
     *
     * @return array<array-key, mixed>|null
     */
    public function config(string $id): ?array
    {
        return self::decodedConfig($this->configOf($id));
    }

    /**
     * A config_json value as read from a row, decoded by the rule {@see config()} applies.
     *
     * @return array<array-key, mixed>|null
     */
    public static function decodedConfig(mixed $raw): ?array
    {
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Up to `$limit` project ids, in id order.
     *
     * @return list<string>
     */
    public function ids(int $limit): array
    {
        $statement = $this->pdo->prepare('SELECT id FROM projects ORDER BY id LIMIT :limit');
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return array_map(strval(...), $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * The project's name and stored configuration with its active scan's scanner-set hash, or null when it has no active scan.
     *
     * @return array{name: mixed, config_json: mixed, active_scan_id: mixed, scanner_set_hash: mixed}|null
     */
    public function withActiveScan(string $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT p.name, p.config_json, p.active_scan_id, s.scanner_set_hash FROM projects p JOIN scans s ON s.id = p.active_scan_id WHERE p.id = :id',
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        /** @var array{name: mixed, config_json: mixed, active_scan_id: mixed, scanner_set_hash: mixed}|null */
        return is_array($row) ? $row : null;
    }

    /**
     * How long the project's active scan took and how many files it wrote, or null when it has no active scan.
     *
     * @return array{duration_ms: mixed, file_count: mixed}|null
     */
    public function activeScanCost(string $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT s.duration_ms, (SELECT COUNT(*) FROM files f WHERE f.last_scan_id = s.id) AS file_count ' .
            'FROM projects p JOIN scans s ON s.id = p.active_scan_id WHERE p.id = :id',
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        /** @var array{duration_ms: mixed, file_count: mixed}|null */
        return is_array($row) ? $row : null;
    }

    /**
     * Every other project that holds notes, most recently updated first; bundles excluded.
     *
     * @return list<array{id: mixed, name: mixed, root_realpath: mixed}>
     */
    public function annotatedExcept(string $id): array
    {
        $statement = $this->pdo->prepare(
            "SELECT p.id, p.name, p.root_realpath FROM projects p WHERE p.id <> :project AND p.root_realpath NOT LIKE 'bundle://%' " .
            'AND EXISTS (SELECT 1 FROM annotations a WHERE a.project_id = p.id) ORDER BY p.updated_at DESC, p.id',
        );
        $statement->execute(['project' => $id]);

        /** @var list<array{id: mixed, name: mixed, root_realpath: mixed}> */
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * One page of the catalog, most recently updated first: each project with
     * its active and latest scan and its row counts. Up to one row past
     * `$limit`, so the caller can tell whether another page follows.
     *
     * @return list<array<string, mixed>>
     */
    public function catalogPage(int $limit, int $offset): array
    {
        $statement = $this->pdo->prepare(
            'SELECT p.id, p.name, p.root_realpath, p.active_scan_id, p.created_at, p.updated_at, ' .
            'active.mode AS active_mode, active.status AS active_status, active.started_at AS active_started_at, ' .
            'active.finished_at AS active_finished_at, latest.id AS latest_scan_id, latest.mode AS latest_mode, ' .
            'latest.status AS latest_status, latest.started_at AS latest_started_at, latest.finished_at AS latest_finished_at, ' .
            '(SELECT COUNT(*) FROM files f WHERE f.project_id = p.id) AS file_count, ' .
            '(SELECT COUNT(*) FROM nodes n WHERE n.project_id = p.id) AS node_count, ' .
            '(SELECT COUNT(*) FROM edges e WHERE e.project_id = p.id) AS edge_count, ' .
            '(SELECT COUNT(*) FROM diagnostics d WHERE d.project_id = p.id) AS diagnostic_count ' .
            'FROM projects p LEFT JOIN scans active ON active.id = p.active_scan_id ' .
            'LEFT JOIN scans latest ON latest.id = (SELECT s.id FROM scans s WHERE s.project_id = p.id ' .
            // Within one second, the order the scans were made in: their ids are hashes.
            'ORDER BY s.started_at DESC, s.rowid DESC LIMIT 1) ' .
            'ORDER BY p.updated_at DESC, p.id ASC LIMIT :limit OFFSET :offset',
        );
        $statement->bindValue(':limit', $limit + 1, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        /** @var list<array<string, mixed>> */
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Projects in (created_at, id) order after the given keyset position: up
     * to one past `$limit`, so the caller can tell whether more follow.
     *
     * @return list<array{id: string, name: string, created_at: string}>
     */
    public function inCreationOrder(int $limit, string $afterCreatedAt, string $afterId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name, created_at FROM projects ' .
            'WHERE created_at > :after_created OR (created_at = :same_created AND id > :after_id) ' .
            'ORDER BY created_at ASC, id ASC LIMIT :limit',
        );
        $statement->bindValue(':after_created', $afterCreatedAt);
        $statement->bindValue(':same_created', $afterCreatedAt);
        $statement->bindValue(':after_id', $afterId);
        $statement->bindValue(':limit', $limit + 1, PDO::PARAM_INT);
        $statement->execute();

        /** @var list<array{id: string, name: string, created_at: string}> */
        return $statement->fetchAll(PDO::FETCH_ASSOC);
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
