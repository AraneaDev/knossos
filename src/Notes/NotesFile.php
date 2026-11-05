<?php

declare(strict_types=1);

namespace Knossos\Notes;

use InvalidArgumentException;
use JsonException;
use Knossos\Query\AnnotationService;

/**
 * The on-disk format for exported annotations. Encoding and decoding live
 * together so the file's shape is defined in one place, and decoding checks
 * everything an import would otherwise trip over later.
 *
 * @phpstan-type NoteRow array{canonical_name: string, kind: string, author: 'user'|'model', value: string, basis_json: string, created_at: string, updated_at: string}
 * @phpstan-type Origin array{exported_at: string, root: string, project_id: string, knossos_version: string}
 */
final class NotesFile
{
    public const FORMAT = 'knossos-notes';
    public const VERSION = 1;
    public const DEFAULT_NAME = 'knossos-notes.json';

    private const ORIGIN_FIELDS = ['exported_at', 'root', 'project_id', 'knossos_version'];
    private const STRING_FIELDS = ['canonical_name', 'kind', 'author', 'value', 'created_at', 'updated_at'];

    /**
     * Write the file text. The table keeps basis as a JSON string; the file
     * holds it as a real array so a person can read and diff it.
     *
     * @param Origin $origin
     * @param list<NoteRow> $rows
     */
    public static function encode(array $origin, array $rows): string
    {
        $notes = [];
        foreach ($rows as $row) {
            $notes[] = [
                'canonical_name' => $row['canonical_name'],
                'kind' => $row['kind'],
                'author' => $row['author'],
                'value' => $row['value'],
                'basis' => json_decode($row['basis_json'], true, flags: JSON_THROW_ON_ERROR),
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
            ];
        }

        return json_encode(
            ['format' => self::FORMAT, 'version' => self::VERSION, 'origin' => $origin, 'notes' => $notes],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Read and validate file text, returning rows in the table's shape.
     *
     * @return array{origin: Origin, rows: list<NoteRow>}
     * @throws InvalidArgumentException NOTES_FILE_INVALID or NOTES_FILE_VERSION
     */
    public static function decode(string $json): array
    {
        try {
            $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('NOTES_FILE_INVALID: not valid JSON (' . $e->getMessage() . ').', 0, $e);
        }
        if (!is_array($data) || ($data['format'] ?? null) !== self::FORMAT) {
            throw new InvalidArgumentException('NOTES_FILE_INVALID: not a ' . self::FORMAT . ' file.');
        }
        if (($data['version'] ?? null) !== self::VERSION) {
            throw new InvalidArgumentException('NOTES_FILE_VERSION: this file is version ' . json_encode($data['version'] ?? null) . '; this Knossos reads version ' . self::VERSION . '.');
        }

        $source = $data['origin'] ?? null;
        if (!is_array($source)) {
            throw new InvalidArgumentException('NOTES_FILE_INVALID: origin is missing.');
        }
        $origin = [];
        foreach (self::ORIGIN_FIELDS as $field) {
            if (!is_string($source[$field] ?? null)) {
                throw new InvalidArgumentException("NOTES_FILE_INVALID: origin.{$field} must be a string.");
            }
            $origin[$field] = $source[$field];
        }

        $notes = $data['notes'] ?? null;
        if (!is_array($notes) || !array_is_list($notes)) {
            throw new InvalidArgumentException('NOTES_FILE_INVALID: notes must be a list.');
        }
        $rows = [];
        $seen = [];
        foreach ($notes as $index => $note) {
            $row = self::decodeRow($note, $index);
            // Two copies of one key would make the counts wrong and the winner an accident of order.
            $key = $row['canonical_name'] . '/' . $row['kind'] . '/' . $row['author'];
            if (isset($seen[$key])) {
                throw new InvalidArgumentException('NOTES_FILE_INVALID: duplicate note ' . $key);
            }
            $seen[$key] = true;
            $rows[] = $row;
        }

        /** @var Origin $origin */
        return ['origin' => $origin, 'rows' => $rows];
    }

    /**
     * Validate one note and convert it to the table's shape.
     *
     * @return NoteRow
     */
    private static function decodeRow(mixed $note, int $index): array
    {
        if (!is_array($note)) {
            throw new InvalidArgumentException("NOTES_FILE_INVALID: notes[{$index}] must be an object.");
        }
        foreach (self::STRING_FIELDS as $field) {
            if (!is_string($note[$field] ?? null)) {
                throw new InvalidArgumentException("NOTES_FILE_INVALID: notes[{$index}].{$field} must be a string.");
            }
        }
        if (!in_array($note['kind'], AnnotationService::KINDS, true)) {
            throw new InvalidArgumentException("NOTES_FILE_INVALID: notes[{$index}].kind must be one of: " . implode(', ', AnnotationService::KINDS) . '.');
        }
        if (!in_array($note['author'], AnnotationService::AUTHORS, true)) {
            throw new InvalidArgumentException("NOTES_FILE_INVALID: notes[{$index}].author must be one of: " . implode(', ', AnnotationService::AUTHORS) . '.');
        }
        if (!is_array($note['basis'] ?? null)) {
            throw new InvalidArgumentException("NOTES_FILE_INVALID: notes[{$index}].basis must be an array.");
        }

        // Built field by field, in the table's column order, so a file with
        // extra keys cannot smuggle them into an import.
        $row = [
            'canonical_name' => $note['canonical_name'],
            'kind' => $note['kind'],
            'author' => $note['author'],
            'value' => $note['value'],
            'basis_json' => json_encode($note['basis'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'created_at' => $note['created_at'],
            'updated_at' => $note['updated_at'],
        ];

        /** @var NoteRow $row */
        return $row;
    }
}
