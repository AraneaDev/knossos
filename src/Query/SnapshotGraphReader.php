<?php

declare(strict_types=1);

namespace Knossos\Query;

use Closure;
use InvalidArgumentException;
use Knossos\Scanner\Protocol\ErasedTypeEdge;
use Knossos\Store\EnrichedFacts;
use Knossos\Store\SnapshotBounds;
use Knossos\Store\SnapshotPayload;
use PDO;

/**
 * One snapshot's graph, read with only the columns a comparison of two
 * graphs uses: which component is which (id, language, kind, names, origin,
 * file and line, and the attributes that excuse it from the dead-code count),
 * which edges join them (with the attributes of an import, which say whether
 * it is erased at runtime), their roles, the diagnostics' severities, the
 * boundaries and the files' paths.
 *
 * Its readers are the branch comparison, the quality gate and the trend
 * report ({@see QualityGateQueryService::branchComparison()},
 * {@see QualityGateQueryService::qualityGate()},
 * {@see QualityGateQueryService::architectureTrends()}), and snapshot_diff
 * a row of an archived table at a time ({@see self::eachArchivedRow()}).
 *
 * A stored row carries far more (owners, hashes, the scan it came from),
 * and a snapshot holds tens of thousands of rows: reading every column of
 * two whole graphs cost a branch comparison about 425 MB. The
 * active graph is read with a column list; an archived one is inflated and
 * read a row at a time, so its JSON and its decoded rows are never held
 * whole. Its stored text is read from SQLite a piece at a time where the
 * runtime offers an incremental blob reader (PHP 8.4 and later); on PHP 8.3
 * it is fetched as one string, at its compressed size.
 */
final readonly class SnapshotGraphReader
{
    /** The columns kept, per table: everything the comparisons, the gate and the trends read. */
    public const COLUMNS = [
        'files' => ['id', 'relative_path'],
        // `attributes_json`: the dead-code count leaves out what the attributes say is reached another way (an override, a runtime-invoked member, a script, a type).
        // `language`: a component is identified across two graphs by language, kind and name; a name alone is not unique.
        'nodes' => ['id', 'language', 'kind', 'canonical_name', 'display_name', 'origin', 'file_id', 'start_line', 'attributes_json'],
        // `attributes_json` only for the kinds {@see ErasedTypeEdge} reads, null for every other edge: a cycle over a type-only import is not one.
        'edges' => ['kind', 'source_id', 'target_id', 'attributes_json'],
        'classifications' => ['node_id', 'role'],
        'diagnostics' => ['severity'],
        // Counted by the trend report.
        'boundaries' => ['id'],
    ];

    /** The most rows one table of the active graph may hold, as the snapshot diff bounds it. */
    private const ROW_LIMIT = SnapshotBounds::MAX_ROWS_PER_TABLE;

    /**
     * Reads through the project's database connection.
     *
     * @param bool $blobReads read stored payloads through SQLite's blob stream
     *   where the connection offers one; false always fetches them as one
     *   string, the PHP 8.3 path, so a test can cover that path on any runtime
     */
    public function __construct(private PDO $pdo, private bool $blobReads = true) {}

    /**
     * The active graph's rows, ordered by id as the snapshot diff and the archive order them.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function active(string $projectId, string $scanId): array
    {
        $facts = [];
        foreach (self::COLUMNS as $table => $columns) {
            $select = array_map(static fn(string $column): string => $table === 'edges' && $column === 'attributes_json'
                ? sprintf("CASE WHEN kind IN ('%s') THEN attributes_json END AS attributes_json", implode("', '", ErasedTypeEdge::KINDS))
                : $column, $columns);
            // Native rows only, as the archive stores them ({@see EnrichedFacts}).
            $native = EnrichedFacts::nativeCondition($table);
            // Sorted by id here rather than in SQL. Ordered by the id index,
            // SQLite would walk every project's rows; sorting the project's
            // rows itself, it spilled them to a temporary file, 1.5 s for
            // 100,000 edges, where PHP sorts the rows it holds anyway in
            // 0.2 s. `SORT_STRING` compares bytes, as SQLite's ordering does.
            // Past the limit the table is refused whichever rows came first.
            // The id is read to sort by and dropped where the table's columns
            // leave it out.
            $statement = $this->pdo->prepare(sprintf('SELECT id AS sort_id, %s FROM %s WHERE project_id = :project%s LIMIT %d', implode(', ', $select), $table, $native, self::ROW_LIMIT + 1));
            $statement->execute(['project' => $projectId]);
            $rows = [];
            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                $id = (string) $row['sort_id'];
                unset($row['sort_id']);
                $rows[$id] = $row;
            }
            ksort($rows, SORT_STRING);
            $rows = array_values($rows);
            if (count($rows) > self::ROW_LIMIT) {
                $count = $this->pdo->prepare(sprintf('SELECT COUNT(*) FROM %s WHERE project_id = :project%s', $table, $native));
                $count->execute(['project' => $projectId]);
                throw new SnapshotTooLargeException($scanId, $table, (int) $count->fetchColumn(), self::ROW_LIMIT);
            }
            $facts[$table] = $rows;
        }

        return $facts;
    }

    /**
     * An archived snapshot's rows, in the payload's order, with the payload read from the store.
     *
     * A compressed payload is decoded and inflated a few kilobytes at a time
     * and its rows are taken off the front as they arrive, each decoded on its
     * own and cut to its columns: neither the payload's JSON, nor its binary
     * form, nor its decoded arrays are ever held whole, and no step's output
     * depends on how well the payload compresses. A payload this reading does
     * not recognise (plain JSON from an earlier version, laid out another way)
     * is decoded whole instead, which gives the same rows.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function archivedById(string $scanId): array
    {
        return $this->readStored($scanId, self::COLUMNS);
    }

    /**
     * Each row of one archived table, every column, handed to `$sink` as it is
     * read, so a caller that keeps only what it needs of each row never holds
     * the table. A payload that can only be decoded whole still is, and its
     * rows are then handed over one by one.
     *
     * @param Closure(array<string, mixed>): void $sink
     */
    public function eachArchivedRow(string $scanId, string $table, Closure $sink): void
    {
        // An object, so the reads below see the count the sink keeps.
        $emitted = new \stdClass();
        $emitted->rows = 0;
        $counting = static function (array $row) use ($emitted, $sink): void {
            ++$emitted->rows;
            $sink($row);
        };
        $columns = [$table => null];
        $blob = $this->payloadBlob($scanId);
        if ($blob !== null) {
            try {
                $read = fread($blob, strlen(self::PREFIX)) === self::PREFIX && $this->streamed(self::pieces($blob), $columns, $counting) !== null;
            } finally {
                fclose($blob);
            }
            if ($read) {
                return;
            }
        }
        // A stream that broke off after handing over rows cannot be read again
        // without handing them over twice.
        if ($emitted->rows > 0) {
            throw new InvalidArgumentException(sprintf('Snapshot archive payload is invalid: %s', $scanId));
        }
        $statement = $this->pdo->prepare('SELECT payload_json FROM scan_snapshots WHERE scan_id = :scan');
        $statement->execute(['scan' => $scanId]);
        $stored = $statement->fetchColumn();
        if (!is_string($stored)) {
            throw new InvalidArgumentException(sprintf('Snapshot facts are not retained: %s', $scanId));
        }
        if (str_starts_with($stored, self::PREFIX) && $this->streamed(self::slices($stored), $columns, $counting) !== null) {
            return;
        }
        if ($emitted->rows > 0) {
            throw new InvalidArgumentException(sprintf('Snapshot archive payload is invalid: %s', $scanId));
        }
        foreach ($this->decodedWhole($stored, $scanId, $columns)[$table] as $row) {
            $sink($row);
        }
    }

    /**
     * The tables named in `$columns` from an archived payload, each cut to its
     * columns (null keeps them all).
     *
     * @param array<string, list<string>|null> $columns
     * @return array<string, list<array<string, mixed>>>
     */
    private function read(string $storedPayload, string $scanId, array $columns): array
    {
        $facts = str_starts_with($storedPayload, self::PREFIX) ? $this->streamed(self::slices($storedPayload), $columns) : null;

        return $facts ?? $this->decodedWhole($storedPayload, $scanId, $columns);
    }

    /**
     * The tables named in `$columns` from the payload stored for `$scanId`.
     *
     * Where SQLite's incremental blob reader is available (`Pdo\Sqlite::openBlob()`,
     * PHP 8.4 and later, on a connection {@see \Knossos\Store\SqliteConnection}
     * opens as `Pdo\Sqlite`), the payload is read through it a piece at a
     * time and never held whole, compressed or not. On PHP 8.3 there is no
     * such reader: a `PDO::PARAM_LOB` column there is the whole value behind a
     * stream. The payload is then fetched as one string, at its compressed
     * size, and decoded exactly the same way. Only the byte source differs.
     *
     * @param array<string, list<string>|null> $columns
     * @return array<string, list<array<string, mixed>>>
     */
    private function readStored(string $scanId, array $columns): array
    {
        $blob = $this->payloadBlob($scanId);
        if ($blob !== null) {
            try {
                $facts = fread($blob, strlen(self::PREFIX)) === self::PREFIX ? $this->streamed(self::pieces($blob), $columns) : null;
            } finally {
                fclose($blob);
            }
            if ($facts !== null) {
                return $facts;
            }
        }
        $statement = $this->pdo->prepare('SELECT payload_json FROM scan_snapshots WHERE scan_id = :scan');
        $statement->execute(['scan' => $scanId]);
        $stored = $statement->fetchColumn();
        if (!is_string($stored)) {
            throw new InvalidArgumentException(sprintf('Snapshot facts are not retained: %s', $scanId));
        }

        return $this->read($stored, $scanId, $columns);
    }

    /**
     * A read-only stream over the stored payload, or null where the connection cannot open one.
     *
     * Feature-detected rather than version-checked: `openBlob` exists only on
     * a `Pdo\Sqlite` connection (PHP 8.4 and later), and is called through a
     * callable so the code also analyses and runs on 8.3, where it is absent.
     *
     * @return resource|null
     */
    private function payloadBlob(string $scanId)
    {
        $open = [$this->pdo, 'openBlob'];
        if (!$this->blobReads || !is_callable($open)) {
            return null;
        }
        // Only a stored text or blob value can be opened. openBlob() on a
        // NULL value, or on a row deleted since, emits an E_WARNING before it
        // returns false, and the suite fails on any byte of stderr.
        $statement = $this->pdo->prepare("SELECT rowid FROM scan_snapshots WHERE scan_id = :scan AND typeof(payload_json) IN ('text', 'blob')");
        $statement->execute(['scan' => $scanId]);
        $rowid = $statement->fetchColumn();
        if ($rowid === false) {
            return null;
        }
        // The row can still go between the two statements; a failed open is
        // then a fallback to the fetched string, not a warning.
        set_error_handler(static fn(): bool => true, E_WARNING);
        try {
            $blob = $open('scan_snapshots', 'payload_json', (int) $rowid);
        } finally {
            restore_error_handler();
        }

        return is_resource($blob) ? $blob : null;
    }

    /**
     * A stored payload string after its prefix, a slice at a time.
     *
     * @return \Generator<int, string>
     */
    private static function slices(string $storedPayload): \Generator
    {
        for ($at = strlen(self::PREFIX), $length = strlen($storedPayload); $at < $length; $at += self::SLICE) {
            yield substr($storedPayload, $at, self::SLICE);
        }
    }

    /**
     * A payload stream after its prefix, a slice at a time.
     *
     * @param resource $blob
     * @return \Generator<int, string>
     */
    private static function pieces($blob): \Generator
    {
        while (!feof($blob)) {
            $piece = fread($blob, self::SLICE);
            if ($piece === false || $piece === '') {
                return;
            }
            yield $piece;
        }
    }

    /** Marks a compressed payload, as {@see SnapshotPayload} writes it. */
    private const PREFIX = 'gzip64:';

    /**
     * How much stored payload (base64 text, a multiple of 4) is decoded and
     * inflated in one step: 4,096 characters, 3,072 compressed bytes.
     *
     * Small on purpose. What one step inflates to is its input times the
     * compression ratio, and zlib's ratio reaches about 1,032:1; a megabyte
     * per step let a payload of repeated attributes inflate whole in one call
     * (41 MB at once for 20,001 identical 2 KB attributes). At 3 KB a step
     * produces at most about 3 MB, whatever the payload holds.
     */
    private const SLICE = 4096;

    /** The longest unfinished text kept between steps: no row is longer, so a buffer past it is not a payload this reads. */
    private const MAX_BUFFER = 16 << 20;

    /** The next table's name and the bracket opening its rows. */
    private const TABLE = '/\G\s*,?\s*"([a-z_]+)"\s*:\s*\[/';

    /** One flat row: an object of scalars and strings, nothing nested. */
    private const ROW = '/\G\s*,?\s*(\{(?:[^{}"]++|"(?:[^"\\\\]++|\\\\.)*+")*+\})/';

    /**
     * The rows of a compressed payload, read as it inflates; null when it is
     * not laid out as the archive writes it (or cannot be inflated), so the
     * caller decodes it whole.
     *
     * The base64 text arrives in pieces of any length (a stream need not
     * return whole slices); each step decodes the longest run of whole
     * four-character groups it has and carries the rest to the next.
     *
     * @param iterable<string> $pieces the stored text after its prefix
     * @param array<string, list<string>|null> $columns
     * @param (Closure(array<string, mixed>): void)|null $sink takes each kept row instead of the result
     * @return array<string, list<array<string, mixed>>>|null
     */
    private function streamed(iterable $pieces, array $columns, ?Closure $sink = null): ?array
    {
        $inflate = @inflate_init(ZLIB_ENCODING_GZIP);
        if ($inflate === false) {
            return null;
        }
        $facts = array_fill_keys(array_keys($columns), []);
        $state = ['phase' => 'head', 'table' => null, 'buffer' => ''];
        $carry = '';
        foreach ($pieces as $piece) {
            $text = $carry . $piece;
            $whole = strlen($text) - strlen($text) % 4;
            $carry = substr($text, $whole);
            $compressed = base64_decode(substr($text, 0, $whole), true);
            $chunk = $compressed === false ? false : @inflate_add($inflate, $compressed, ZLIB_SYNC_FLUSH);
            if ($chunk === false || !$this->take($state, $chunk, $facts, $columns, $sink)) {
                return null;
            }
        }
        $chunk = $carry === '' ? @inflate_add($inflate, '', ZLIB_FINISH) : false;
        if ($chunk === false || !$this->take($state, $chunk, $facts, $columns, $sink)) {
            return null;
        }

        return $state['phase'] === 'done' && trim($state['buffer']) === '' ? $facts : null;
    }

    /**
     * Read what `$chunk` completes: the head, then each table's rows, cut to
     * the columns kept. What is left unfinished stays in the buffer for the
     * next chunk. False when the text is not what the archive writes.
     *
     * @param array{phase: string, table: ?string, buffer: string} $state
     * @param array<string, list<array<string, mixed>>> $facts
     * @param array<string, list<string>|null> $columns the tables to keep, each with its columns or null for all
     * @param (Closure(array<string, mixed>): void)|null $sink takes each kept row instead of `$facts`
     */
    private function take(array &$state, string $chunk, array &$facts, array $columns, ?Closure $sink = null): bool
    {
        $buffer = $state['buffer'] . $chunk;
        $at = 0;
        $length = strlen($buffer);
        while ($at < $length) {
            if ($state['phase'] === 'head') {
                if (preg_match('/\G\{"schema":\d+,"facts":\{/', $buffer, $match, 0, $at) !== 1) {
                    return $length < 64;
                }
                $at += strlen($match[0]);
                $state['phase'] = 'tables';
            } elseif ($state['phase'] === 'tables') {
                if (preg_match('/\G\s*\}\s*\}/', $buffer, $match, 0, $at) === 1) {
                    $at += strlen($match[0]);
                    $state['phase'] = 'done';
                } elseif (preg_match(self::TABLE, $buffer, $match, 0, $at) === 1) {
                    $at += strlen($match[0]);
                    $state['phase'] = 'rows';
                    $state['table'] = $match[1];
                } else {
                    break;
                }
            } elseif ($state['phase'] === 'rows') {
                if (preg_match('/\G\s*\]/', $buffer, $match, 0, $at) === 1) {
                    $at += strlen($match[0]);
                    $state['phase'] = 'tables';
                } elseif (preg_match(self::ROW, $buffer, $match, 0, $at) === 1) {
                    $at += strlen($match[0]);
                    if (array_key_exists((string) $state['table'], $columns)) {
                        /** @var array<string, mixed>|null $row a flat object decodes to string keys */
                        $row = json_decode($match[1], true, 4);
                        if (!is_array($row)) {
                            return false;
                        }
                        $kept = $columns[$state['table']];
                        $row = $kept === null ? $row : self::kept((string) $state['table'], $row, $kept);
                        if ($sink !== null) {
                            $sink($row);
                        } else {
                            $facts[$state['table']][] = $row;
                        }
                    }
                } else {
                    break;
                }
            } else {
                break;
            }
        }
        $state['buffer'] = substr($buffer, $at);

        // A row is never longer than this: a buffer that grew past it without a match is not a payload this reads.
        return strlen($state['buffer']) < self::MAX_BUFFER;
    }

    /**
     * One archived row cut to the columns kept, with an edge's attributes
     * dropped unless its kind is one {@see ErasedTypeEdge} reads, exactly as
     * the active graph's query reads them.
     *
     * @param array<string, mixed> $row
     * @param list<string> $columns
     * @return array<string, mixed>
     */
    private static function kept(string $table, array $row, array $columns): array
    {
        $row = array_intersect_key($row, array_flip($columns));
        if ($table === 'edges' && array_key_exists('attributes_json', $row) && !in_array($row['kind'] ?? null, ErasedTypeEdge::KINDS, true)) {
            $row['attributes_json'] = null;
        }

        return $row;
    }

    /**
     * The rows of a payload decoded whole, cut to the columns kept (null keeps every column).
     *
     * Only for a payload the streamed read does not recognise, which earlier
     * versions wrote as plain JSON.
     *
     * @param array<string, list<string>|null> $columns
     * @return array<string, list<array<string, mixed>>>
     */
    private function decodedWhole(string $storedPayload, string $scanId, array $columns): array
    {
        $payload = json_decode(SnapshotPayload::decode($storedPayload), true, 512, JSON_THROW_ON_ERROR);
        $tables = is_array($payload) ? ($payload['facts'] ?? null) : null;
        if (!is_array($tables)) {
            throw new InvalidArgumentException(sprintf('Snapshot archive payload is invalid: %s', $scanId));
        }
        $facts = [];
        foreach ($columns as $table => $kept) {
            $rows = $tables[$table] ?? [];
            $facts[$table] = $kept === null ? array_values($rows) : array_map(static fn(array $row): array => self::kept($table, $row, $kept), $rows);
        }

        return $facts;
    }
}
