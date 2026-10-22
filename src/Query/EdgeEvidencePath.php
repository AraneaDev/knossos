<?php

declare(strict_types=1);

namespace Knossos\Query;

use Knossos\Reconciliation\NamedFiles;

/**
 * The path an edge's evidence is in, for a query reading edges as `e` beside
 * their file row as `f`.
 *
 * A file component's own edges, such as what a shell script launches, point
 * into a file no worker scans, which has no file row: their evidence lives at
 * the path the component names.
 */
final class EdgeEvidencePath
{
    /** The SQL expression, without an alias. */
    public static function sql(): string
    {
        return sprintf(
            "COALESCE(f.relative_path, (SELECT json_extract(fc.attributes_json, '$.path') FROM nodes fc WHERE fc.id = e.source_id AND fc.owner_key = '%s'))",
            NamedFiles::OWNER,
        );
    }
}
