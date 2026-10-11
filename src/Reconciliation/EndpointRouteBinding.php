<?php

declare(strict_types=1);

namespace Knossos\Reconciliation;

/** The edges and diagnostics joining a scan's endpoints to its routes. */
final readonly class EndpointRouteBinding
{
    /**
     * @param array<string, array<string, mixed>> $edges
     * @param list<array<string, string|int>> $warnings
     */
    public function __construct(public array $edges, public array $warnings) {}
}
