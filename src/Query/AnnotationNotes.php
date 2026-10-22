<?php

declare(strict_types=1);

namespace Knossos\Query;

use Closure;
use Knossos\Result\ResultEnvelope;
use Knossos\Store\ProjectRepository;
use PDO;

/**
 * The notes a result carries for the components it names.
 *
 * Each note says who wrote it and when. A model's note also says whether it
 * still rests on the code it was written against: every file in its basis is
 * compared with the file's current hash, and a changed or missing file makes
 * the note stale, with the first such file named, and a note resting on no
 * scanned file at all says so. A component's own file that changed leaves
 * the note standing when the component's own lines there did not. A
 * person's note rests on nothing and is never stale. Notes come person's
 * first, then in the order the result named the components, capped so they
 * cannot crowd out the answer they ride on.
 */
final readonly class AnnotationNotes
{
    /** The most notes one result carries. */
    public const MAX_NOTES = 10;

    /** The most characters of a note's text a result carries; the full text stays in list_annotations. */
    public const MAX_TEXT = 1000;

    /** How many component names a result's notes are looked up for; a long listing's first ones are enough. */
    public const NOTE_LOOKUP_NAMES = 300;

    public function __construct(private PDO $pdo) {}

    /**
     * The result with the notes of the components it names, as `data.notes`:
     * every `canonical_name` its data holds (a target, usage sources,
     * dependants, flow hops, candidates, a file's components), looked up in
     * {@see self::forComponents()}. A result naming no annotated component is
     * returned unchanged, so it pays nothing.
     *
     * @param list<string> $alsoNamed components the result bears on without naming them in its data
     */
    public function attach(ResultEnvelope $result, array $alsoNamed = []): ResultEnvelope
    {
        $names = array_fill_keys($alsoNamed, true);
        $collect = static function (mixed $value) use (&$collect, &$names): void {
            if (!is_array($value) || count($names) >= self::NOTE_LOOKUP_NAMES) {
                return;
            }
            foreach ($value as $key => $item) {
                if ($key === 'canonical_name' && is_string($item)) {
                    $names[$item] = true;
                } elseif (is_array($item)) {
                    $collect($item);
                }
            }
        };
        $collect($result->data);
        $notes = $this->forComponents($result->projectId, array_keys($names));

        return $notes === [] ? $result : $result->withData([...$result->data, 'notes' => $notes]);
    }

    /**
     * The notes on the named components, the person's first, capped and cut.
     * A person's `check` request is among them even without a reason: it is
     * a request, not text.
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
                'WHERE project_id = ? AND canonical_name IN (' . implode(',', array_fill(0, count($chunk), '?')) . ") AND (value <> '' OR kind = 'check')",
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
        $spanHolds = $this->spanCheck($projectId);

        return array_map(function (array $row) use ($current, $spanHolds): array {
            $reason = $row['author'] === 'model' ? self::staleReason((string) $row['canonical_name'], (string) $row['basis_json'], $current, $spanHolds) : null;
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
     * Why each of these notes is stale, in their order, or null for one that
     * is not: a model note whose basis changed or is gone. A person's note
     * rests on nothing and is never stale.
     *
     * @param list<array<string, mixed>> $rows annotation rows with `canonical_name`, `author` and `basis_json`
     * @return list<?string>
     */
    public function staleness(string $projectId, array $rows): array
    {
        $models = array_values(array_filter($rows, static fn(array $row): bool => $row['author'] === 'model'));
        $current = $this->currentHashes($projectId, $models);
        $spanHolds = $this->spanCheck($projectId);

        return array_map(static fn(array $row): ?string => $row['author'] === 'model' ? self::staleReason((string) $row['canonical_name'], (string) $row['basis_json'], $current, $spanHolds) : null, $rows);
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
        // A file recorded by its disk hash is checked on disk, whatever a scan
        // says of it since, under a key of its own; one no longer there is absent.
        $root = null;
        foreach ($rows as $row) {
            foreach (self::basis((string) $row['basis_json']) as $entry) {
                $key = self::currentKey($entry);
                if ($key !== $entry['path'] && !array_key_exists($key, $current)) {
                    $root ??= $this->root($projectId);
                    $current[$key] = $root === null ? null : ProjectFileHash::current($root, $entry['path']);
                }
            }
        }
        return array_filter($current, static fn(?string $hash): bool => $hash !== null);
    }

    /**
     * Where `currentHashes` keeps a basis entry's current hash: by its path
     * for a scanned file, and apart for one recorded by its disk hash.
     *
     * @param array{path: string, hash: string, span: ?string} $entry
     */
    private static function currentKey(array $entry): string
    {
        return str_starts_with($entry['hash'], ProjectFileHash::PREFIX) ? ProjectFileHash::PREFIX . $entry['path'] : $entry['path'];
    }

    /**
     * Whether a component's lines in a file still hash as a note recorded
     * them: the component looked up by name in the active graph, its lines
     * read from the file on disk only when the disk holds the file the scan
     * read, so the scan's line numbers describe it. A component the graph no
     * longer places in that file, or a disk that differs from the scan, does
     * not hold, and the note falls back to the whole file. Files are read once
     * per call.
     *
     * @return Closure(string, string, string, string): bool canonical name, path, scanned hash, recorded span hash
     */
    private function spanCheck(string $projectId): Closure
    {
        $lines = $this->pdo->prepare(
            'SELECT MIN(n.start_line) AS start_line, MAX(n.end_line) AS end_line FROM nodes n JOIN files f ON f.id = n.file_id ' .
            'WHERE n.project_id = :project AND n.canonical_name = :name AND f.relative_path = :path',
        );
        $root = false;
        $contents = [];

        return function (string $canonical, string $path, string $scannedHash, string $recorded) use ($lines, $projectId, &$root, &$contents): bool {
            $lines->execute(['project' => $projectId, 'name' => $canonical, 'path' => $path]);
            $span = $lines->fetch(PDO::FETCH_ASSOC);
            $lines->closeCursor();
            if (!is_array($span) || !is_numeric($span['start_line']) || !is_numeric($span['end_line'])) {
                return false;
            }
            if ($root === false) {
                $root = $this->root($projectId);
            }
            if ($root === null) {
                return false;
            }
            if (!array_key_exists($path, $contents)) {
                $contents[$path] = ComponentSpan::scannedContents($root, $path, $scannedHash);
            }

            return $contents[$path] !== null && hash_equals($recorded, ComponentSpan::hash($contents[$path], (int) $span['start_line'], (int) $span['end_line']));
        };
    }

    /** The project's root on disk, or null when it is unknown. */
    private function root(string $projectId): ?string
    {
        return (new ProjectRepository($this->pdo))->rootOf($projectId);
    }

    /**
     * Why a model note is stale, naming the first file in its basis that
     * changed or is gone, or null when every one is as it was. A changed own
     * file whose entry records the component's span is as it was when the
     * component's lines there are ({@see self::spanCheck()}).
     *
     * @param array<string, string> $current
     * @param Closure(string, string, string, string): bool $spanHolds
     */
    private static function staleReason(string $canonical, string $basisJson, array $current, Closure $spanHolds): ?string
    {
        $basis = self::basis($basisJson);
        // Written about a component no scanned file declares, citing none:
        // nothing a reader could check it against.
        if ($basis === []) {
            return 'rests on no scanned file';
        }
        foreach ($basis as $entry) {
            $key = self::currentKey($entry);
            if (!isset($current[$key])) {
                return sprintf('written before %s was removed', $entry['path']);
            }
            if ($current[$key] !== $entry['hash'] && ($entry['span'] === null || $key !== $entry['path'] || !$spanHolds($canonical, $entry['path'], $current[$key], $entry['span']))) {
                return sprintf('written before %s changed', $entry['path']);
            }
        }
        return null;
    }

    /**
     * The files a note's stored basis names, each with the hash it had and
     * the hash of the component's span there, or null when it recorded none;
     * an entry that is not a path and a hash is skipped rather than trusted.
     *
     * @return list<array{path: string, hash: string, span: ?string}>
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
                $span = $entry['span'] ?? null;
                $basis[] = ['path' => $entry['path'], 'hash' => $entry['hash'], 'span' => is_array($span) && is_string($span['hash'] ?? null) ? $span['hash'] : null];
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
