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

    /** Bind the database holding the annotations. */
    public function __construct(private PDO $pdo) {}

    /**
     * Write every annotation of a project to a notes file, replacing any file
     * already there. The file is built beside the target and renamed into
     * place, so a failure never leaves a half-written file behind.
     *
     * @return array{file: string, total: int, by_author: array{user: int, model: int}, replaced: bool}
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
     * @return array{executed: bool, file: string, origin: array{exported_at: string, root: string, project_id: string, knossos_version: string}, total: int, new: int, replaced: int, kept: int, unmatched: int, stale: int, conflicts: array{total: int, items: list<array{canonical_name: string, kind: string, author: string, kept: 'file'|'project'}>}}
     * @throws InvalidArgumentException NOTES_FILE_MISSING, NOTES_FILE_INVALID or NOTES_FILE_VERSION
     */
    public function import(string $projectId, string $file, bool $execute): array
    {
        $text = is_file($file) ? @file_get_contents($file) : false;
        if ($text === false) {
            throw new InvalidArgumentException('NOTES_FILE_MISSING: ' . $file . ' does not exist.');
        }
        $decoded = NotesFile::decode($text);
        $plan = $this->plan($projectId, $decoded['rows']);

        if ($execute && $plan['write'] !== []) {
            $this->write($projectId, $plan['write']);
        }

        return [
            'executed' => $execute,
            'file' => $file,
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
     * Upsert the planned rows under the project's writer lease in one
     * transaction, so a failure leaves the notes as they were.
     *
     * @param list<NoteRow> $rows
     */
    private function write(string $projectId, array $rows): void
    {
        $upsert = $this->pdo->prepare(
            'INSERT INTO annotations(project_id, canonical_name, kind, value, author, basis_json, created_at, updated_at) ' .
            'VALUES (:project, :name, :kind, :value, :author, :basis, :created, :updated) ' .
            'ON CONFLICT(project_id, canonical_name, kind, author) DO UPDATE SET ' .
            'value = excluded.value, basis_json = excluded.basis_json, created_at = excluded.created_at, updated_at = excluded.updated_at',
        );
        $lease = (new ProjectWriterLock($this->pdo))->acquire($projectId);
        $this->pdo->beginTransaction();
        try {
            foreach ($rows as $row) {
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
