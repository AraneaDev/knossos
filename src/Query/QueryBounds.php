<?php

declare(strict_types=1);

namespace Knossos\Query;

/**
 * The ceilings every whole-graph query shares: dependency_cycles,
 * architecture_health, check_architecture, suggest_location and the test
 * reach walk read at most this many edges, and the cycle and health searches
 * hold at most this many nodes. One place, so the catalogue, the argument
 * checks and the queries cannot drift apart.
 */
final class QueryBounds
{
    public const MAX_EDGES = 500_000;

    public const MAX_NODES = 200_000;
}
