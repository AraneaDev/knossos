<?php

declare(strict_types=1);

namespace Knossos\Query;

use PDO;

/**
 * One cell of the boundary heat map spelled out for the architecture pane:
 * which components of boundary `from` depend on which of boundary `to`, the
 * pairs with the most dependency edges first.
 *
 * Read-only: it never scans. The boundaries are named as the dashboard's
 * {@see BoundaryMatrix} names its axes (each component under its one
 * {@see BoundaryLabels} label), and the count runs over the same bounded edge
 * walk, so `edges` is the cell's figure and `truncated` says when it is a
 * floor. A name no component carries answers with no couplings, not an error:
 * the graph may have moved on since the pane drew its map.
 */
final readonly class BoundaryCouplingsService
{
    /** Pairs listed: the pane shows the top few beside the map. */
    public const LIMIT = 5;

    /** The walk the cell is counted with. */
    private BoundaryMatrix $matrix;

    /**
     * @param PDO $pdo an existing, migrated graph database
     * @param BoundaryMatrix|null $matrix the walk to count with; the default bounds, unless a test narrows them
     */
    public function __construct(private PDO $pdo, ?BoundaryMatrix $matrix = null)
    {
        $this->matrix = $matrix ?? new BoundaryMatrix($pdo);
    }

    /**
     * The couplings from `$from` to `$to` in the project that owns `$path`,
     * or an `unscanned` envelope when no scanned project contains it.
     *
     * @return array<string, mixed>
     */
    public function couplings(string $path, string $from, string $to): array
    {
        $envelope = ['project_id' => null, 'snapshot_id' => null, 'from' => $from, 'to' => $to, 'edges' => 0, 'couplings' => [], 'truncated' => false, 'truncation_reasons' => []];

        return PaneProject::answer($this->pdo, $path, $envelope, function (array $project, array $envelope) use ($from, $to): array {
            $found = $this->matrix->couplings($project['id'], BoundaryLabels::load($this->pdo, $project['id']), $from, $to, self::LIMIT);

            return ['status' => 'ok'] + array_replace($envelope, $found);
        });
    }
}
