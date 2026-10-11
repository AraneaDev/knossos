<?php

declare(strict_types=1);

namespace Knossos\Store;

/**
 * The node kinds every importing or calling file declares for itself.
 *
 * A `package`, an `endpoint` and an `external_*` stand-in are not owned by
 * any one file: the node is filed under whichever file named it first, so its
 * file says nothing about where it lives. Anything that lets a node's file
 * stand for ownership (a file's fan-in, the components a changed file holds,
 * the files a boundary spans) leaves these out, or two unrelated callers of
 * one URL end up depending on each other through the first caller's file.
 */
final class SharedNodeKinds
{
    /** Kinds shared by name; `external_*` is shared by prefix. */
    public const KINDS = ['package', 'endpoint'];

    public const EXTERNAL_PREFIX = 'external_';

    private function __construct() {}

    /** Whether a node of this kind is declared by every file that names it. */
    public static function isSharedByDesign(string $kind): bool
    {
        return in_array($kind, self::KINDS, true) || str_starts_with($kind, self::EXTERNAL_PREFIX);
    }

    /**
     * An SQL condition holding for a node whose file stands for its owner.
     *
     * @param string $column the node's kind column, qualified by its alias
     */
    public static function ownedByFileSql(string $column): string
    {
        $kinds = implode(', ', array_map(static fn(string $kind): string => "'" . $kind . "'", self::KINDS));

        return $column . " NOT LIKE 'external\\_%' ESCAPE '\\' AND " . $column . ' NOT IN (' . $kinds . ')';
    }
}
