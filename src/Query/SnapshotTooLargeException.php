<?php

declare(strict_types=1);

namespace Knossos\Query;

use InvalidArgumentException;

/**
 * A snapshot holds more rows in one table than a read of it whole takes:
 * which table, how many rows it holds, and the limit, so a caller can say
 * what was too large instead of only that something was.
 */
final class SnapshotTooLargeException extends InvalidArgumentException
{
    /**
     * @param string $scanId the snapshot read
     * @param string $table the table past the limit
     * @param int $rows the rows that table holds for the snapshot
     * @param int $limit the most rows a whole read takes
     */
    public function __construct(public readonly string $scanId, public readonly string $table, public readonly int $rows, public readonly int $limit)
    {
        parent::__construct(sprintf('Active snapshot %s holds %d rows in %s, past the %d-row limit a snapshot is read whole within.', $scanId, $rows, $table, $limit));
    }

    /**
     * The limit as an answer states it.
     *
     * @return array{code: string, table: string, rows: int, limit: int}
     */
    public function reason(): array
    {
        return ['code' => 'row_limit', 'table' => $this->table, 'rows' => $this->rows, 'limit' => $this->limit];
    }
}
