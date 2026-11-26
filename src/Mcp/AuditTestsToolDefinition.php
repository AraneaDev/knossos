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
                    'tier' => ['type' => 'string', 'enum' => TestFidelityQueryService::TIERS, 'description' => 'scan (the default; all in scope git-diff): stored findings; on_request: computed now (OVER_MOCKING, STALE_SINCE, FIXTURE_DRIFT), never stored; all: both.'],
                    'scope' => ['type' => 'string', 'enum' => TestFidelityQueryService::SCOPES, 'default' => 'all', 'description' => 'git-diff: STALE_SINCE, and stored findings narrowed to those whose file or target file changed since base_ref (staged, unstaged and untracked).'],
                    'base_ref' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200, 'description' => 'The ref scope git-diff compares the working tree with; HEAD when omitted.'],
                    'timeout_ms' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10000, 'default' => 2000, 'description' => 'Bound on the on-request tier, checked between its phases (the git call, each graph read), not within one; past it the result is partial. The git call of scope git-diff is capped at 5000, and its timeout is an error.'],
                ],
                'required' => ['project_id'],
                'additionalProperties' => false,
            ],
        ];
    }
}
