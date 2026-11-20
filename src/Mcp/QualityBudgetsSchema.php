<?php

declare(strict_types=1);

namespace Knossos\Mcp;

/**
 * The quality budgets a gate can enforce, kept out of {@see ToolCatalog}, which is at its length cap.
 */
final readonly class QualityBudgetsSchema
{
    /**
     * The schema of the budgets, the same set wherever budgets are accepted.
     *
     * `quality_gate` named its seven limits; `review_diff` accepted any key with
     * an integer value. The gate itself rejects an unknown key at runtime, so
     * nothing was silently skipped, but the schema is the only description an
     * agent gets before calling, and it described two different contracts.
     * An empty object stays valid: `review_diff` treats `{}` as "no budgets".
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        $limit = ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000];

        return [
            'type' => 'object',
            'properties' => [
                'new_cycles' => $limit,
                'boundary_violations' => [...$limit, 'description' => 'Requires policies: there is nothing to count violations against without them.'],
                'error_diagnostics' => $limit,
                'warning_diagnostics' => $limit,
                'hub_degree_growth' => $limit,
                'unreferenced_candidates' => $limit,
                'public_surface_changes' => $limit,
                'new_definite_test_findings' => [...$limit, 'description' => 'Definite test-double findings whose key the baseline lacks; indeterminate (and failing) when the baseline snapshot predates stored test findings.'],
            ],
            'additionalProperties' => false,
        ];
    }
}
