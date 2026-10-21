<?php

declare(strict_types=1);

namespace Knossos\Query;

/**
 * One page of a result read one row past its limit: the rows up to the
 * limit, and whether the extra row proved that more exist.
 */
final class Page
{
    /**
     * The first `$limit` of `$rows`, and whether `$rows` held more.
     *
     * @template T
     *
     * @param list<T> $rows
     * @return array{0: list<T>, 1: bool}
     */
    public static function cut(array $rows, int $limit): array
    {
        return [array_slice($rows, 0, $limit), count($rows) > $limit];
    }
}
