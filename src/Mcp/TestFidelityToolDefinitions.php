<?php

declare(strict_types=1);

namespace Knossos\Mcp;

/**
 * The test-fidelity tools' definitions beside `audit_tests`, kept out of
 * {@see ToolCatalog}, which is at its length cap. The catalogue spreads every
 * definition this returns, so a tool added here is listed at once.
 */
final readonly class TestFidelityToolDefinitions
{
    /**
     * Every definition, in the order the catalogue lists them.
     *
     * @return list<array<string, mixed>>
     */
    public static function definitions(): array
    {
        return [self::listTestRules()];
    }

    /**
     * `list_test_rules`: the rule catalogue, which takes no project.
     *
     * @return array<string, mixed>
     */
    private static function listTestRules(): array
    {
        return [
            'name' => 'list_test_rules',
            'title' => 'List test rules',
            'description' => 'List the rules audit_tests reports, with each one\'s category, tier (scan or on_request), confidences and sub-rules. Use to read a finding\'s rule or to write a test_finding_suppressions entry.',
            'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            'inputSchema' => [
                'type' => 'object',
                'properties' => ToolCatalog::commonReadProperties(withRefresh: false),
                'additionalProperties' => false,
            ],
        ];
    }
}
