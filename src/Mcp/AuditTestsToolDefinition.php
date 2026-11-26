<?php

declare(strict_types=1);

namespace Knossos\Mcp;

use Knossos\Query\TestFidelityQueryService;
use Knossos\TestFidelity\TestRuleCatalog;

/**
 * The `audit_tests` tool's definition, kept out of {@see ToolCatalog}, which is at its length cap.
 */
final readonly class AuditTestsToolDefinition
{
    /**
     * The definition: a project, an optional path prefix, the confidence and
     * category to list, and the tier and scope to list them from. Its category
     * and confidence lists are {@see TestRuleCatalog}'s.
     *
     * @return array<string, mixed>
     */
    public static function definition(): array
    {
        return [
            'name' => 'audit_tests',
            'title' => 'Audit tests',
            'description' => 'List test doubles that no longer match the code they replace and assertions that cannot fail. Use before trusting a green suite, or after changing a signature.',
            'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    ...ToolCatalog::commonReadProperties(),
                    'project_id' => ['type' => 'string', 'minLength' => 1],
                    'path_prefix' => ['type' => 'string', 'minLength' => 1],
                    'confidence' => ['type' => 'string', 'enum' => TestRuleCatalog::confidences()],
                    'category' => ['type' => 'string', 'enum' => TestRuleCatalog::categories(), 'description' => 'An on-request category implies its tier.'],
                    'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000, 'default' => 0],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50],
                    'tier' => ['type' => 'string', 'enum' => TestFidelityQueryService::TIERS, 'default' => 'scan', 'description' => 'scan: stored findings; on_request: computed now (OVER_MOCKING), never stored; all: both.'],
                    'scope' => ['type' => 'string', 'enum' => TestFidelityQueryService::SCOPES, 'default' => 'all'],
                    'base_ref' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
                    'timeout_ms' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10000, 'default' => 2000, 'description' => 'Bound on the on-request tier; past it the result is partial.'],
                ],
                'required' => ['project_id'],
                'additionalProperties' => false,
            ],
        ];
    }
}
