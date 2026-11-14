<?php

declare(strict_types=1);

namespace Knossos\Store;

use PDO;

/**
 * Reads and writes the derived per-folder facts and companions of a project.
 *
 * These rows are not scan-owned graph data: they are recomputed from the graph
 * and from git, and `replace` rewrites only what differs, so a scan that
 * changed nothing in a folder leaves its row and the file's write count alone.
 */
final readonly class FolderFactsStore
{
    private const COLUMNS = ['project_id', 'directory', 'extension', 'role', 'files', 'facts_json'];

    private const KEY = ['project_id', 'directory', 'extension', 'role'];

    public function __construct(private PDO $pdo) {}

    /**
     * Make a project's rows exactly `$rows`, writing only the difference.
     *
     * Rows of the project that are absent from `$rows` are deleted, rows whose
     * `files` or facts differ are upserted, and equal rows are not touched.
     *
     * @param list<array{directory: string, extension: string, role: string, files: int, facts: array<string, mixed>}> $rows
     * @return int how many rows were written or deleted
     */
    public function replace(string $projectId, array $rows): int
    {
        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            $changed = $this->write($projectId, $rows);
            if ($own) {
                $this->pdo->commit();
            }
        } catch (\Throwable $failure) {
            if ($own && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $failure;
        }

        return $changed;
    }

    /**
     * The deletes and upserts of `replace`, run inside whatever transaction is open.
     *
     * @param list<array{directory: string, extension: string, role: string, files: int, facts: array<string, mixed>}> $rows
     */
    private function write(string $projectId, array $rows): int
    {
        $wanted = [];
        foreach ($rows as $row) {
            $wanted[self::keyOf($row['directory'], $row['extension'], $row['role'])] = $row;
        }

        $changed = 0;
        $existing = $this->pdo->prepare('SELECT directory, extension, role FROM folder_facts WHERE project_id = ?');
        $existing->execute([$projectId]);
        $delete = $this->pdo->prepare('DELETE FROM folder_facts WHERE project_id = ? AND directory = ? AND extension = ? AND role = ?');
        foreach ($existing->fetchAll(PDO::FETCH_NUM) as [$directory, $extension, $role]) {
            if (!isset($wanted[self::keyOf((string) $directory, (string) $extension, (string) $role)])) {
                $delete->execute([$projectId, $directory, $extension, $role]);
                $changed += $delete->rowCount();
            }
        }

        $upsert = $this->pdo->prepare(Upsert::sql('folder_facts', self::COLUMNS, self::KEY, 1));
        foreach ($wanted as $row) {
            $upsert->execute([$projectId, $row['directory'], $row['extension'], $row['role'], $row['files'], self::encode($row['facts'])]);
            $changed += $upsert->rowCount();
        }

        return $changed;
    }

    /** Delete every folder fact of the project, so {@see self::current()} is false until they are built again. */
    public function clear(string $projectId): void
    {
        $this->pdo->prepare('DELETE FROM folder_facts WHERE project_id = ?')->execute([$projectId]);
    }

    /**
     * Whether the project has folder facts and every row of them is of `$version`.
     *
     * A project scanned before folder facts existed has none, and one scanned by
     * an older version has rows whose `v` differs or is missing; a scan that
     * finds nothing changed uses this to know it must build them anyway.
     */
    public function current(string $projectId, int $version): bool
    {
        $statement = $this->pdo->prepare(
            "SELECT EXISTS (SELECT 1 FROM folder_facts WHERE project_id = ?)
                AND NOT EXISTS (SELECT 1 FROM folder_facts WHERE project_id = ? AND json_extract(facts_json, '$.v') IS NOT ?)",
        );
        $statement->bindValue(1, $projectId);
        $statement->bindValue(2, $projectId);
        $statement->bindValue(3, $version, PDO::PARAM_INT);
        $statement->execute();

        return (int) $statement->fetchColumn() === 1;
    }

    /**
     * The facts of one folder population, or null when none were stored.
     *
     * @return array{files: int, facts: array<string, mixed>}|null
     */
    public function of(string $projectId, string $directory, string $extension, string $role): ?array
    {
        $statement = $this->pdo->prepare('SELECT files, facts_json FROM folder_facts WHERE project_id = ? AND directory = ? AND extension = ? AND role = ?');
        $statement->execute([$projectId, $directory, $extension, $role]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return ['files' => (int) $row['files'], 'facts' => self::decode((string) $row['facts_json'])];
    }

    /**
     * The populations of a directory and of every directory below it, for one extension and role, in directory order.
     *
     * The root, the empty directory, holds every row. Below another directory
     * a row is matched by the byte range `dir/` to `dir0` (the character after
     * the slash), so a `%` or `_` in a name is only a character and case counts,
     * which a LIKE would get wrong on both.
     *
     * @return list<array{directory: string, files: int, facts: array<string, mixed>}>
     */
    public function under(string $projectId, string $directory, string $extension, string $role): array
    {
        $sql = 'SELECT directory, files, facts_json FROM folder_facts WHERE project_id = ? AND extension = ? AND role = ?';
        $statement = $this->pdo->prepare($directory === '' ? $sql : $sql . ' AND (directory = ? OR (directory >= ? AND directory < ?))');
        $statement->execute($directory === '' ? [$projectId, $extension, $role] : [$projectId, $extension, $role, $directory, $directory . '/', $directory . '0']);
        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_NUM) as [$name, $files, $json]) {
            $rows[] = ['directory' => (string) $name, 'files' => (int) $files, 'facts' => self::decode((string) $json)];
        }
        usort($rows, static fn(array $a, array $b): int => strcmp($a['directory'], $b['directory']));

        return $rows;
    }

    /**
     * The test-path patterns the project's source folders show for an extension, sorted and distinct.
     *
     * A pattern counts when its folder has at least `$minFiles` files and at least
     * `$numerator` in `$denominator` of them follow it; the caller passes the
     * threshold a brief applies before it names an expected test, so the store
     * holds no policy. Read from every folder so a path in a folder with no source
     * files of its own can still be recognised as a test by the shape its name has
     * elsewhere.
     *
     * @return list<string>
     */
    public function testPathPatterns(string $projectId, string $extension, int $minFiles, int $numerator, int $denominator): array
    {
        $statement = $this->pdo->prepare("SELECT files, facts_json FROM folder_facts WHERE project_id = ? AND extension = ? AND role = 'source'");
        $statement->execute([$projectId, $extension]);
        $patterns = [];
        foreach ($statement->fetchAll(PDO::FETCH_NUM) as [$files, $json]) {
            $testPath = self::decode((string) $json)['test_path'] ?? null;
            if (is_array($testPath) && is_string($testPath['pattern'] ?? null) && is_int($testPath['files'] ?? null) && (int) $files >= $minFiles && $testPath['files'] * $denominator >= (int) $files * $numerator) {
                $patterns[$testPath['pattern']] = true;
            }
        }
        $patterns = array_map('strval', array_keys($patterns));
        sort($patterns, SORT_STRING);

        return $patterns;
    }

    /**
     * Replace the project's companions with the result of one git run.
     *
     * Stored whole, keyed by the HEAD they were computed at, so a later read can
     * tell with `companionsHead` whether they are still current.
     *
     * @param array<string, list<array{path: string, commits: int, of: int}>> $byDirectory
     */
    public function saveCompanions(string $projectId, string $gitHead, string $status, array $byDirectory): void
    {
        $this->pdo->prepare(
            'INSERT INTO folder_companions(project_id, git_head, status, companions_json) VALUES (?, ?, ?, ?)
             ON CONFLICT(project_id) DO UPDATE SET git_head = excluded.git_head, status = excluded.status, companions_json = excluded.companions_json',
        )->execute([$projectId, $gitHead, $status, self::encode($byDirectory)]);
    }

    /** The git HEAD the stored companions were computed at, or null when none are stored. */
    public function companionsHead(string $projectId): ?string
    {
        $statement = $this->pdo->prepare('SELECT git_head FROM folder_companions WHERE project_id = ?');
        $statement->execute([$projectId]);
        $head = $statement->fetchColumn();

        return $head === false ? null : (string) $head;
    }

    /**
     * The companions of one directory, empty when none are stored for it.
     *
     * @return list<array{path: string, commits: int, of: int}>
     */
    public function companionsOf(string $projectId, string $directory): array
    {
        $statement = $this->pdo->prepare('SELECT companions_json FROM folder_companions WHERE project_id = ?');
        $statement->execute([$projectId]);
        $json = $statement->fetchColumn();
        if ($json === false) {
            return [];
        }
        /** @var array<string, list<array{path: string, commits: int, of: int}>> $all */
        $all = self::decode((string) $json);

        return $all[$directory] ?? [];
    }

    /** One string per population, so the wanted set can be looked up by key. */
    private static function keyOf(string $directory, string $extension, string $role): string
    {
        return $directory . "\0" . $extension . "\0" . $role;
    }

    /**
     * JSON with every object's keys sorted, so equal facts are byte-equal and
     * the change guard in the upsert sees no difference where there is none.
     *
     * @param array<array-key, mixed> $value
     */
    private static function encode(array $value): string
    {
        return json_encode(self::sorted($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * The stored JSON back as data; it was written by `encode`, so a failure is a corrupt row and throws.
     *
     * @return array<array-key, mixed>
     */
    private static function decode(string $json): array
    {
        /** @var array<array-key, mixed> */
        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Sort the keys of every object in the value, leaving lists in their order.
     *
     * @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private static function sorted(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sorted($item);
            }
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
