<?php

declare(strict_types=1);

namespace Knossos\Query;

use Knossos\Store\SnapshotFacts;

/**
 * What `search_architecture` never matches unless asked: the test doubles
 * and test cases a scan derives from test code, and the attributes that list
 * the names something else declares.
 */
final class SearchExclusions
{
    /**
     * The attributes that list the names a node's members or exports declare
     * (inherited ones included), which search never matches: every subclass
     * of a class would otherwise match each name its parent declares.
     */
    private const NAME_LIST_ATTRIBUTES = ['exports', 'exports_open', 'member_names', 'member_names_open'];

    private function __construct() {}

    /**
     * A condition, joined with a leading ` AND `, holding for a `json_tree`
     * row outside every {@see self::NAME_LIST_ATTRIBUTES} list.
     */
    public static function outsideNameLists(string $alias): string
    {
        // SQLite quotes a key holding anything but letters and digits (`$."member_names"[0]`); without the quotes the key reads the same either way.
        $path = "replace({$alias}.fullkey, '\"', '')";
        $outside = array_map(
            static fn(string $name): string => "{$path} <> '\$.{$name}' AND {$path} NOT GLOB '\$.{$name}[[]*'",
            self::NAME_LIST_ATTRIBUTES,
        );

        return ' AND ' . implode(' AND ', $outside);
    }

    /**
     * A condition holding for a node that is not a test double or test case:
     * facts derived from test code, found only when a kind filter asks for them.
     */
    public static function notDerivedTestFact(string $alias): string
    {
        return sprintf("%s.kind NOT IN ('%s')", $alias, implode("', '", SnapshotFacts::DERIVED_NODE_KINDS));
    }
}
