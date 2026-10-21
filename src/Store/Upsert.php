<?php

declare(strict_types=1);

namespace Knossos\Store;

/**
 * The one insert-or-update statement shape the graph writer uses for every table.
 *
 * Built from the column list so a single-row write and a batch write of the
 * same table cannot drift apart: both get the same SET list and the same change
 * guard that leaves an unchanged row (and its last_scan_id) untouched.
 */
final class Upsert
{
    /**
     * The statement for $rows rows of $columns into $table.
     *
     * Every column except id, project_id and the conflict target is updated on
     * conflict, and only when one of those columns (other than last_scan_id)
     * actually differs.
     *
     * @param list<string> $columns
     * @param list<string> $conflict the unique key the conflict clause names
     */
    public static function sql(string $table, array $columns, array $conflict, int $rows): string
    {
        $row = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
        $updated = array_values(array_diff($columns, ['id', 'project_id'], $conflict));
        $sql = sprintf(
            'INSERT INTO %s(%s) VALUES %s ON CONFLICT(%s) DO UPDATE SET %s',
            $table,
            implode(', ', $columns),
            rtrim(str_repeat($row . ',', $rows), ','),
            implode(', ', $conflict),
            implode(', ', array_map(static fn(string $column): string => sprintf('%s = excluded.%s', $column, $column), $updated)),
        );
        $compared = array_values(array_diff($updated, ['last_scan_id']));

        return $sql . ' WHERE ' . implode(' OR ', array_map(
            static fn(string $column): string => sprintf('%s.%s IS NOT excluded.%s', $table, $column, $column),
            $compared,
        ));
    }
}
