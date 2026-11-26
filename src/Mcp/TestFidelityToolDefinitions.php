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
        return [self::listTestRules(), self::verifyTestDoubles(), self::checkFixtures()];
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

    /**
     * `verify_test_doubles`: every double of one class, interface or trait, with its stored findings.
     *
     * @return array<string, mixed>
     */
    private static function verifyTestDoubles(): array
    {
        return [
            'name' => 'verify_test_doubles',
            'title' => 'Verify test doubles',
            'description' => 'List every test double of one class, interface or trait, each with its stored findings and whether it is still valid (no definite finding). Use before changing a type that tests double.',
            'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    ...ToolCatalog::commonReadProperties(),
                    'project_id' => ['type' => 'string', 'minLength' => 1],
                    'symbol' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500, 'description' => 'The canonical or display name, matched exactly; a name several types share is an error listing their canonical names.'],
                ],
                'required' => ['project_id', 'symbol'],
                'additionalProperties' => false,
            ],
        ];
    }

    /**
     * `check_fixtures`: the project's JSON and YAML fixtures against the types whose records they hold.
     *
     * @return array<string, mixed>
     */
    private static function checkFixtures(): array
    {
        return [
            'name' => 'check_fixtures',
            'title' => 'Check fixtures',
            'description' => 'Check the JSON and YAML fixture files under test and fixture directories against the project type each holds records of, and list FIXTURE_DRIFT warnings: a field missing or no longer declared, a value of the wrong kind, an enum value that is no case. Use after changing a DTO, an entity or an enum.',
            'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    ...ToolCatalog::commonReadProperties(),
                    'project_id' => ['type' => 'string', 'minLength' => 1],
                    'paths' => ['type' => 'array', 'maxItems' => 50, 'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 1000], 'description' => 'Project-relative fixture files or directories to check instead of every fixture; each JSON or YAML file under one is checked.'],
                    'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000, 'default' => 0],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50],
                ],
                'required' => ['project_id'],
                'additionalProperties' => false,
            ],
        ];
    }
}
