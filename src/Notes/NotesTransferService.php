<?php

declare(strict_types=1);

namespace Knossos\Notes;

use InvalidArgumentException;
use Knossos\Query\AnnotationNotes;
use Knossos\Runtime\Version;
use Knossos\Scan\ProjectWriterLock;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Moves a project's annotations in and out of a notes file.
 *
 * @phpstan-import-type NoteRow from NotesFile
 */
final readonly class NotesTransferService
{
    /** How many conflicts a result lists; the total is always reported. */
    private const CONFLICTS_LISTED = 50;

    /** The largest notes file read, in bytes; a real one is far smaller. */
    public const MAX_BYTES = 10 * 1024 * 1024;

    /** Bind the database holding the annotations. */
    public function __construct(private PDO $pdo) {}

    /**
     * Write every annotation of a project to a notes file, replacing any file
     * already there. The file is built beside the target and renamed into
     * place, so a failure never leaves a half-written file behind.
     *
     * @return array{file: string, total: int, by_author: array{user: int, model: int}, replaced: bool}
     * @throws InvalidArgumentException NOTES_FILE_NOT_NOTES when a file in the way is not a notes file
     * @throws \JsonException when a stored basis is not valid JSON
     * @throws RuntimeException when the file cannot be written
     */
    public function export(string $projectId, string $root, string $file): array
    {
        $rows = $this->rowsOf($projectId);
        $byAuthor = ['user' => 0, 'model' => 0];
        foreach ($rows as $row) {
            ++$byAuthor[$row['author']];
        }

        // Encoding first: a corrupt basis throws before any file exists.
        $text = NotesFile::encode([
            'exported_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'root' => $root,
            'project_id' => $projectId,
            'knossos_version' => Version::CURRENT,
        ], $rows);

        $replaced = file_exists($file);
        // Only a previous notes file may be replaced; anything else is somebody's data.
        if ($replaced) {
            $existing = is_file($file) && filesize($file) <= self::MAX_BYTES ? @file_get_contents($file) : false;
            if ($existing === false || !NotesFile::looksLikeNotesFile($existing)) {
                throw new InvalidArgumentException('NOTES_FILE_NOT_NOTES: ' . $file . ' exists and is not a knossos notes file; remove it or pick another --file.');
            }
        }
        $temp = $file . '.tmp-' . bin2hex(random_bytes(4));
        if (@file_put_contents($temp, $text . "\n") === false || !@rename($temp, $file)) {
            @unlink($temp);
            throw new RuntimeException('NOTES_FILE_WRITE_FAILED: could not write ' . $file . '.');
        }

        return ['file' => $file, 'total' => count($rows), 'by_author' => $byAuthor, 'replaced' => $replaced];
    }

    /**
     * Read a notes file into a project, or preview doing so. A note that
     * clashes on (name, kind, author) is replaced only when the file's copy
     * is strictly later; the project's own wins a tie. Notes on components the
     * project lacks, and stale model notes, are imported and only counted.
     * Rows keep the file's timestamps. Lock and busy errors propagate.
     *
     * @return array{executed: bool, file: string, sha256: string, origin: array{exported_at: string, root: string, project_id: string, knossos_version: string}, total: int, new: int, replaced: int, kept: int, unmatched: int, stale: int, conflicts: array{total: int, items: list<array{canonical_name: string, kind: string, author: string, kept: 'file'|'project'}>}}
     * @param string|null $confineTo a directory the file must really lie in (symlinks followed); null for none
     * @param string|null $expectHash the `sha256` a preview answered: the file must still hash to it, so what is
     *        imported is what the person saw; null to take the file as it is
     * @throws InvalidArgumentException NOTES_FILE_MISSING, NOTES_FILE_OUTSIDE_ROOT, NOTES_FILE_TOO_LARGE, NOTES_FILE_INVALID, NOTES_FILE_VERSION or NOTES_FILE_CHANGED
     */
    public function import(string $projectId, string $file, bool $execute, ?string $confineTo = null, ?string $expectHash = null): array
    {
        $decoded = self::load($file, $confineTo);
        if ($expectHash !== null && !hash_equals($expectHash, $decoded['sha256'])) {
            throw new InvalidArgumentException('NOTES_FILE_CHANGED: ' . $file . ' changed since it was previewed; preview it again.');
        }
        // On execute the plan is made inside the lease and transaction, so the
        // counts describe what was written; a preview just reads.
        $plan = $execute ? $this->planAndWrite($projectId, $decoded['rows']) : $this->plan($projectId, $decoded['rows']);

        return [
            'executed' => $execute,
            'file' => $file,
            'sha256' => $decoded['sha256'],
            'origin' => $decoded['origin'],
            'total' => count($decoded['rows']),
            'new' => $plan['new'],
            'replaced' => $plan['replaced'],
            'kept' => $plan['kept'],
            'unmatched' => $plan['unmatched'],
            'stale' => $plan['stale'],
            'conflicts' => ['total' => count($plan['conflicts']), 'items' => array_slice($plan['conflicts'], 0, self::CONFLICTS_LISTED)],
        ];
    }

    /**
     * Read and validate a notes file without touching any project, so a caller
     * can refuse before it changes anything.
     *
     * @param string|null $confineTo a directory the file must really lie in (symlinks followed); null for none
     * @return array{origin: array{exported_at: string, root: string, project_id: string, knossos_version: string}, rows: list<NoteRow>, sha256: string}
     * @throws InvalidArgumentException NOTES_FILE_MISSING, NOTES_FILE_OUTSIDE_ROOT, NOTES_FILE_TOO_LARGE, NOTES_FILE_INVALID or NOTES_FILE_VERSION
     */
    public static function load(string $file, ?string $confineTo): array
    {
        if (!is_file($file)) {
            throw new InvalidArgumentException('NOTES_FILE_MISSING: ' . $file . ' does not exist.');
        }
        // Confined, the file is read by the path that was checked, not by the
        // name given: a link swapped after the check cannot redirect the read.
        $path = $file;
        if ($confineTo !== null) {
            $real = realpath($file);
            $root = realpath($confineTo);
            if ($real === false || $root === false || !str_starts_with($real, rtrim($root, '/') . '/') || !is_file($real)) {
                throw new InvalidArgumentException('NOTES_FILE_OUTSIDE_ROOT: ' . $file . ' must be a file inside ' . $confineTo . '.');
            }
            $path = $real;
        }
        $size = @filesize($path);
        if ($size === false || $size > self::MAX_BYTES) {
            throw new InvalidArgumentException('NOTES_FILE_TOO_LARGE: ' . $file . ' is over ' . (self::MAX_BYTES / 1024 / 1024) . ' MB.');
        }
        $text = @file_get_contents($path, false, null, 0, self::MAX_BYTES + 1);
        if ($text === false) {
            throw new InvalidArgumentException('NOTES_FILE_MISSING: ' . $file . ' could not be read.');
        }
        if (strlen($text) > self::MAX_BYTES) {
            throw new InvalidArgumentException('NOTES_FILE_TOO_LARGE: ' . $file . ' is over ' . (self::MAX_BYTES / 1024 / 1024) . ' MB.');
        }

        return NotesFile::decode($text) + ['sha256' => hash('sha256', $text)];
    }

    /**
     * Sort the incoming rows against what the project holds, and count what
     * writing them would leave unmatched and stale.
     *
     * @param list<NoteRow> $rows
     * @return array{write: list<NoteRow>, new: int, replaced: int, kept: int, unmatched: int, stale: int, conflicts: list<array{canonical_name: string, kind: string, author: string, kept: 'file'|'project'}>}
     */
    private function plan(string $projectId, array $rows): array
    {
        $lookup = $this->pdo->prepare('SELECT updated_at FROM annotations WHERE project_id = :project AND canonical_name = :name AND kind = :kind AND author = :author');
        $present = $this->pdo->prepare(
            'SELECT EXISTS (SELECT 1 FROM nodes n WHERE n.project_id = :project AND n.canonical_name = :name) ' .
            'OR EXISTS (SELECT 1 FROM files f WHERE f.project_id = :project AND f.relative_path = :name)',
        );
        $write = [];
        $new = $replaced = $kept = $unmatched = 0;
        $conflicts = [];
        foreach ($rows as $row) {
            $lookup->execute(['project' => $projectId, 'name' => $row['canonical_name'], 'kind' => $row['kind'], 'author' => $row['author']]);
            $existing = $lookup->fetchColumn();
            if ($existing !== false && strcmp($row['updated_at'], (string) $existing) <= 0) {
                ++$kept;
                $conflicts[] = ['canonical_name' => $row['canonical_name'], 'kind' => $row['kind'], 'author' => $row['author'], 'kept' => 'project'];
                continue;
            }
            if ($existing === false) {
                ++$new;
            } else {
                ++$replaced;
                $conflicts[] = ['canonical_name' => $row['canonical_name'], 'kind' => $row['kind'], 'author' => $row['author'], 'kept' => 'file'];
            }
            $write[] = $row;
            $present->execute(['project' => $projectId, 'name' => $row['canonical_name']]);
            if ((int) $present->fetchColumn() === 0) {
                ++$unmatched;
            }
        }
        $stale = count(array_filter((new AnnotationNotes($this->pdo))->staleness($projectId, $write), static fn(?string $reason): bool => $reason !== null));

        return ['write' => $write, 'new' => $new, 'replaced' => $replaced, 'kept' => $kept, 'unmatched' => $unmatched, 'stale' => $stale, 'conflicts' => $conflicts];
    }

    /**
     * Plan and upsert under the project's writer lease in one transaction, so
     * a failure leaves the notes as they were. The upsert also refuses to
     * overwrite a row stamped later than the file's, because
     * `annotate_component` writes without the lease.
     *
     * @param list<NoteRow> $rows
     * @return array{write: list<NoteRow>, new: int, replaced: int, kept: int, unmatched: int, stale: int, conflicts: list<array{canonical_name: string, kind: string, author: string, kept: 'file'|'project'}>}
     */
    private function planAndWrite(string $projectId, array $rows): array
    {
        $upsert = $this->pdo->prepare(
            'INSERT INTO annotations(project_id, canonical_name, kind, value, author, basis_json, created_at, updated_at) ' .
            'VALUES (:project, :name, :kind, :value, :author, :basis, :created, :updated) ' .
            'ON CONFLICT(project_id, canonical_name, kind, author) DO UPDATE SET ' .
            'value = excluded.value, basis_json = excluded.basis_json, created_at = excluded.created_at, updated_at = excluded.updated_at ' .
            'WHERE excluded.updated_at > annotations.updated_at',
        );
        $lease = (new ProjectWriterLock($this->pdo))->acquire($projectId);
        $this->pdo->beginTransaction();
        try {
            $plan = $this->plan($projectId, $rows);
            foreach ($plan['write'] as $row) {
                $upsert->execute([
                    'project' => $projectId,
                    'name' => $row['canonical_name'],
                    'kind' => $row['kind'],
                    'value' => $row['value'],
                    'author' => $row['author'],
                    'basis' => $row['basis_json'],
                    'created' => $row['created_at'],
                    'updated' => $row['updated_at'],
                ]);
            }
            $this->pdo->commit();

            return $plan;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        } finally {
            $lease->release();
        }
    }

    /**
     * Every annotation row of the project in a stable order.
     *
     * @return list<NoteRow>
     */
    private function rowsOf(string $projectId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT canonical_name, kind, author, value, basis_json, created_at, updated_at FROM annotations ' .
            'WHERE project_id = :project ORDER BY canonical_name, kind, author',
        );
        $statement->execute(['project' => $projectId]);
        /** @var list<NoteRow> */
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
