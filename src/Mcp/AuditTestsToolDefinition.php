<?php

declare(strict_types=1);

namespace Knossos\Mcp;

use Knossos\TestFidelity\{FindingCategory, FindingConfidence};

/**
 * The `audit_tests` tool's definition, kept out of {@see ToolCatalog}, which is at its length cap.
 */
final readonly class AuditTestsToolDefinition
{
    /**
     * The definition: a project, an optional path prefix and the confidence and category to list.
     *
     * @return array<string, mixed>
     */
    public static function definition(): array
    {
        $confidences = array_map(static fn(FindingConfidence $case): string => $case->value, FindingConfidence::cases());
        $categories = array_map(static fn(FindingCategory $case): string => $case->value, FindingCategory::cases());

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
                    'confidence' => ['type' => 'string', 'enum' => $confidences],
                    'category' => ['type' => 'string', 'enum' => $categories],
                    'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000, 'default' => 0],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50],
                ],
                'required' => ['project_id'],
                'additionalProperties' => false,
            ],
        ];
    }
}
