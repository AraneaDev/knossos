<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Mcp;

use InvalidArgumentException;
use Knossos\Maintenance\DatabaseMaintenanceService;
use Knossos\Mcp\{NextStepPlanner, ResultEnricher, ToolService};
use Knossos\Query\{ArchitectureQueryService, StalenessProbe};
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/** The `creation_brief` tool: the brief of a file about to be created, answered by the server itself. */
#[Group('mcp')]
final class CreationBriefToolTest extends KnossosTestCase
{
    private const DESCRIPTION = 'What a file about to be created should look like: the boundary it lands in and what that may not use, the closest existing files to copy from, the conventions most files in the folder share (each with its count), the files that usually change with a new one there, and the gates that will judge it. Call before creating a file.';

    public function testTheToolIsListedWithItsDescriptionAndSchema(): void
    {
        [$pdo, , $root] = $this->scanTempFixture('turn-brief');
        try {
            $definitions = array_column(self::tools($pdo, $root)->definitions(), null, 'name');

            self::assertArrayHasKey('creation_brief', $definitions);
            $tool = $definitions['creation_brief'];
            self::assertSame(self::DESCRIPTION, $tool['description']);
            self::assertSame(['project_id', 'path'], $tool['inputSchema']['required']);
            foreach (['project_id', 'path', 'verbosity'] as $property) {
                self::assertArrayHasKey($property, $tool['inputSchema']['properties']);
            }
            self::assertFalse($tool['inputSchema']['additionalProperties']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    public function testACallAnswersWithTheBriefAndRefusesAPathOutsideTheProject(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('turn-brief');
        try {
            file_put_contents($root . '/knossos.json', json_encode(['version' => 1, 'boundaries' => [['name' => 'Core', 'path_prefix' => 'src/Core/']], 'gates' => [['name' => 'cs', 'command' => 'make cs']]], JSON_THROW_ON_ERROR));
            $tools = self::tools($pdo, $root);

            $envelope = $tools->call('creation_brief', ['project_id' => $projectId, 'path' => 'src/Core/NewThing.php']);

            self::assertSame($projectId, $envelope->projectId);
            self::assertSame('src/Core/NewThing.php', $envelope->data['path']);
            self::assertFalse($envelope->data['exists']);
            self::assertSame('Core', $envelope->data['placement']['boundary']);
            self::assertSame(['cs'], array_column($envelope->data['gates'], 'name'));
            self::assertSame('New file in Core: 0 conventions, 1 gates.', $envelope->summary);
            self::assertTrue($tools->call('creation_brief', ['project_id' => $projectId, 'path' => $root . '/src/Core/Greeter.php'])->data['exists']);

            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('outside the project');
            $tools->call('creation_brief', ['project_id' => $projectId, 'path' => '../outside.php']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    private static function tools(PDO $pdo, string $root): ToolService
    {
        return new ToolService(
            new ProjectScanService($pdo, self::repositoryRoot(), [$root]),
            ArchitectureQueryService::forDatabase($pdo),
            new DatabaseMaintenanceService($pdo, ':memory:'),
            new ResultEnricher(new StalenessProbe($pdo), new NextStepPlanner()),
        );
    }
}
