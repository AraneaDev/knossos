<?php

declare(strict_types=1);

namespace Knossos\Query;

/** Which way a walk reads a node's edges. */
enum Direction
{
    /** The edges out of the node, from it to what it reaches. */
    case Outgoing;

    /** The edges into the node, from what depends on it. */
    case Incoming;
}
