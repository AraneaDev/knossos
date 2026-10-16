<?php

declare(strict_types=1);

namespace Knossos\Store;

/**
 * The ceilings an archived snapshot, and the active graph read whole beside
 * it, share. The quality gate and the trends hold a baseline and the active
 * graph in memory at once, so the row limit is what keeps them inside the
 * core's memory limit: measured on 2026-10-16, two graphs of 200,000 nodes
 * and 400,000 edges peaked at 819 MB of the core's 1G, and at this limit
 * GateScaleTest holds them under 768 MB.
 */
final class SnapshotBounds
{
    /** More rows than this in any captured table and a snapshot records why instead of its facts. */
    public const MAX_ROWS_PER_TABLE = 300_000;

    /**
     * The same, for a payload's uncompressed size. At about 585 bytes a fact
     * (Knossos's own graph), a graph whose largest table is at the row limit
     * and the rest in the usual proportion comes to well under it, so the row
     * limit is the one that applies; a graph with several tables near the
     * limit at once reaches this one first, and the archive says so.
     */
    public const MAX_PAYLOAD_BYTES = 600_000_000;
}
