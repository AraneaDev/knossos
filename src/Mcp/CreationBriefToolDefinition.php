<?php

declare(strict_types=1);

namespace Knossos\Mcp;

/**
 * The `creation_brief` tool's definition, kept out of {@see ToolCatalog}, which is over its length cap and may not grow.
 */
final readonly class CreationBriefToolDefinition
{
    /**
     * The definition: a project and the path of a file about to be created.
     *
     * @return array<string, mixed>
     */
    public static function definition(): array
    {
        return [
            'name' => 'creation_brief',
            'title' => 'Creation brief',
            'description' => 'What a file about to be created should look like: the boundary it lands in and what that may not use, the closest existing files to copy from, the conventions most files in the folder share (each with its count), the files that usually change with a new one there, and the gates that will judge it. Call before creating a file.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    ...ToolCatalog::commonReadProperties(),
                    'project_id' => ['type' => 'string', 'minLength' => 1],
                    'path' => ['type' => 'string', 'minLength' => 1, 'description' => 'The file to create, relative to the project root or absolute under it. It need not exist.'],
                ],
                'required' => ['project_id', 'path'],
                'additionalProperties' => false,
            ],
            'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
        ];
    }
}
