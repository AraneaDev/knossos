<?php

declare(strict_types=1);

namespace Knossos\Notes;

use Knossos\Runtime\Version;
use PDO;
use RuntimeException;

/**
 * Moves a project's annotations in and out of a notes file.
 *
 * @phpstan-import-type NoteRow from NotesFile
 */
final readonly class NotesTransferService
{
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
