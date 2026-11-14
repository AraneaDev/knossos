<?php

declare(strict_types=1);

namespace Knossos\Scanner\Worker;

/**
 * The read table of one scan request: every path a contribution read and the
 * hash read there, stated once.
 *
 * Every file of a program reads much the same manifests and declarations, so
 * a `reads` map per contribution repeats the same paths and hashes for file
 * after file, a quarter of a large reply. A worker told at `initialize` that
 * the core accepts a read table sends each pair once, as a row of a
 * `scan/read_table` notification, and gives a contribution `reads_ref`, the
 * rows it read. The map is rebuilt here, before the contribution is decoded,
 * so nothing past this point knows how it travelled.
 */
final class ReadTable
{
    /**
     * Row number to its path and hash; null for a path discovery cannot
     * carry, which a `reads` map drops too.
     *
     * @var list<array{string, ?string}|null>
     */
    private array $rows = [];

    /**
     * Take in the rows of one `scan/read_table` notification, numbered on
     * from the rows already held.
     *
     * @throws WorkerException WORKER_CONTRIBUTION_INVALID for rows that are not a list of path and SHA-256-or-null pairs
     */
    public function absorb(mixed $params): void
    {
        $rows = is_array($params) ? ($params['rows'] ?? null) : null;
        if (!is_array($rows) || !array_is_list($rows)) {
            throw self::invalid('read_table rows must be a list.');
        }
        foreach ($rows as $row) {
            if (!is_array($row) || !array_is_list($row) || count($row) !== 2 || !is_string($row[0])) {
                throw self::invalid('A read_table row must be a path and a SHA-256 or null.');
            }
            $this->rows[] = ReadsMap::decode([$row[0] => $row[1]]) === [] ? null : [$row[0], $row[1]];
        }
    }

    /**
     * A contribution's params with `reads_ref` replaced by the `reads` map it
     * stands for; params without one as they are.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @throws WorkerException WORKER_CONTRIBUTION_INVALID for a `reads_ref` beside `reads`, one that is not a list of rows sent, or one naming a path twice with different values
     */
    public function expand(array $params): array
    {
        if (!array_key_exists('reads_ref', $params)) {
            return $params;
        }
        $refs = $params['reads_ref'];
        if (array_key_exists('reads', $params) || !is_array($refs) || !array_is_list($refs)) {
            throw self::invalid('reads_ref must be a list of read_table rows, sent instead of reads.');
        }
        $reads = [];
        foreach ($refs as $ref) {
            if (!is_int($ref) || !array_key_exists($ref, $this->rows)) {
                throw self::invalid(sprintf('reads_ref names row %s, which no read_table part has sent.', is_int($ref) ? (string) $ref : get_debug_type($ref)));
            }
            if ($this->rows[$ref] === null) {
                continue;
            }
            [$path, $hash] = $this->rows[$ref];
            if (array_key_exists($path, $reads) && $reads[$path] !== $hash) {
                throw self::invalid(sprintf('reads_ref names %s twice, with different values.', $path));
            }
            $reads[$path] = $hash;
        }
        unset($params['reads_ref']);
        $params['reads'] = $reads;

        return $params;
    }

    /** The refusal every malformed row or row number gets. */
    private static function invalid(string $message): WorkerException
    {
        return new WorkerException(WorkerErrorCode::ContributionInvalid->value, $message);
    }
}
