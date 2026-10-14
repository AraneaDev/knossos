<?php

declare(strict_types=1);

namespace Knossos\Query;

use PDO;

/**
 * The notes a result carries for the components it names.
 *
 * Each note says who wrote it and when. A model's note also says whether it
 * still rests on the code it was written against: every file in its basis is
 * compared with the file's current hash, and a changed or missing file makes
 * the note stale, with the first such file named, and a note resting on no
 * scanned file at all says so. A person's note rests on nothing and is never
 * stale. Notes come person's first, then in the order the result named the
 * components, capped so they cannot crowd out the answer they ride on.
 */
final readonly class AnnotationNotes
{
    /** The most notes one result carries. */
    public const MAX_NOTES = 10;

    /** The most characters of a note's text a result carries; the full text stays in list_annotations. */
    public const MAX_TEXT = 1000;

    public function __construct(private PDO $pdo) {}

    /**
     * The notes on the named components, the person's first, capped and cut.
     *
     * @param list<string> $canonicalNames
     * @return list<array{component: string, kind: string, author: string, text: string, written: string, stale: bool, stale_reason: ?string}>
     */
    public function forComponents(string $projectId, array $canonicalNames): array
    {
        $names = array_values(array_unique($canonicalNames));
        if ($names === []) {
            return [];
        }
        $rows = [];
        foreach (array_chunk($names, 500) as $chunk) {
            $statement = $this->pdo->prepare(
                'SELECT canonical_name, kind, value, author, basis_json, updated_at FROM annotations ' .
                'WHERE project_id = ? AND canonical_name IN (' . implode(',', array_fill(0, count($chunk), '?')) . ") AND value <> ''",
            );
            $statement->execute([$projectId, ...$chunk]);
            array_push($rows, ...$statement->fetchAll(PDO::FETCH_ASSOC));
        }
        // A person's notes first; then in the order the result named the
        // components, so the one asked about leads its neighbours and the cap
        // never cuts it for an alphabetically earlier dependant.
        $order = array_flip($names);
        usort($rows, static fn(array $a, array $b): int => [$a['author'] === 'user' ? 0 : 1, $order[$a['canonical_name']], $a['kind']] <=> [$b['author'] === 'user' ? 0 : 1, $order[$b['canonical_name']], $b['kind']]);
        $rows = array_slice($rows, 0, self::MAX_NOTES);
        $current = $this->currentHashes($projectId, $rows);

        return array_map(function (array $row) use ($current): array {
            $reason = $row['author'] === 'model' ? $this->staleReason((string) $row['basis_json'], $current) : null;
            return [
                'component' => (string) $row['canonical_name'],
                'kind' => (string) $row['kind'],
                'author' => (string) $row['author'],
                'text' => self::cut((string) $row['value']),
                'written' => substr((string) $row['updated_at'], 0, 10),
                'stale' => $reason !== null,
                'stale_reason' => $reason,
            ];
        }, $rows);
    }

    /**
     * The current hash of every file the given notes rest on, by path; a path
     * the project no longer holds is absent.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, string>
     */
    private function currentHashes(string $projectId, array $rows): array
    {
        $paths = [];
        foreach ($rows as $row) {
            foreach (self::basis((string) $row['basis_json']) as $entry) {
                $paths[$entry['path']] = true;
            }
        }
        if ($paths === []) {
            return [];
        }
        $statement = $this->pdo->prepare(
            'SELECT relative_path, content_hash FROM files WHERE project_id = ? AND relative_path IN (' .
            implode(',', array_fill(0, count($paths), '?')) . ')',
        );
        $statement->execute([$projectId, ...array_keys($paths)]);
        $current = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $file) {
            $current[(string) $file['relative_path']] = (string) $file['content_hash'];
        }
        return $current;
    }

    /**
     * Why a model note is stale, naming the first file in its basis that
     * changed or is gone, or null when every one is as it was.
     *
     * @param array<string, string> $current
     */
    private function staleReason(string $basisJson, array $current): ?string
    {
        $basis = self::basis($basisJson);
        // Written about a component no scanned file declares, citing none:
        // nothing a reader could check it against.
        if ($basis === []) {
            return 'rests on no scanned file';
        }
        foreach ($basis as $entry) {
            if (!isset($current[$entry['path']])) {
                return sprintf('written before %s was removed', $entry['path']);
            }
            if ($current[$entry['path']] !== $entry['hash']) {
                return sprintf('written before %s changed', $entry['path']);
            }
        }
        return null;
    }

    /**
     * The files a note's stored basis names, each with the hash it had; an
     * entry that is not a path and a hash is skipped rather than trusted.
     *
     * @return list<array{path: string, hash: string}>
     */
    private static function basis(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        $basis = [];
        foreach ($decoded as $entry) {
            if (is_array($entry) && is_string($entry['path'] ?? null) && is_string($entry['hash'] ?? null)) {
                $basis[] = ['path' => $entry['path'], 'hash' => $entry['hash']];
            }
        }
        return $basis;
    }

    /** The text cut to {@see self::MAX_TEXT} characters, an ellipsis saying it was. */
    private static function cut(string $text): string
    {
        return mb_strlen($text) <= self::MAX_TEXT ? $text : mb_substr($text, 0, self::MAX_TEXT - 1) . '…';
    }
}
