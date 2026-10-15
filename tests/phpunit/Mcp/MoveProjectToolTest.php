<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Mcp;

use InvalidArgumentException;
use Knossos\Discovery\AllowedRoots;
use Knossos\Maintenance\DatabaseMaintenanceService;
use Knossos\Mcp\{NextStepPlanner, ResultEnricher, ToolService};
use Knossos\Query\{ArchitectureQueryService, StalenessProbe};
use Knossos\Runtime\ServerEnvironment;
use Knossos\Scan\ProjectScanService;
use Knossos\Store\{SqliteGraphRepository, StableId};
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * The tool form of a move. It may move notes but never extends roots.json:
 * that file is the server's read boundary, and a tool able to widen it would
 * let a caller grant itself any directory.
 */
final class MoveProjectToolTest extends KnossosTestCase
{
    private const OLD_ROOT = '/nonexistent/knossos-move-tool/project';

    /** A preview through the tool moves nothing. */
    #[Group('mcp')]
    public function testThePreviewMovesNothing(): void
    {
        [$pdo, $oldId, $newRoot] = $this->movedCheckout();
        try {
            $result = $this->tools($pdo, [$newRoot])->call('move_project', ['from' => self::OLD_ROOT, 'to' => $newRoot]);

            self::assertFalse($result->data['executed']);
            self::assertNull($result->data['roots'], 'The tool never looks at, let alone writes, the roots file.');
            self::assertSame(1, $this->annotationCount($pdo, $oldId));
        } finally {
            $this->removeTempTree($newRoot);
        }
    }

    /** Execute through the tool moves the note to the project the new root scans as. */
    #[Group('mcp')]
    public function testExecuteMovesTheNotes(): void
    {
        [$pdo, , $newRoot] = $this->movedCheckout();
        try {
            $result = $this->tools($pdo, [$newRoot])->call('move_project', ['from' => self::OLD_ROOT, 'to' => $newRoot, 'execute' => true]);

            self::assertTrue($result->data['executed']);
            self::assertSame(1, $this->annotationCount($pdo, ProjectScanService::projectIdOf((string) realpath($newRoot))));
        } finally {
            $this->removeTempTree($newRoot);
        }
    }

    /** A target the server may not read is refused with the command that grants it; nothing is written. */
    #[Group('mcp')]
    public function testATargetOutsideTheRootsIsRefused(): void
    {
        [$pdo, $oldId, $newRoot] = $this->movedCheckout();
        try {
            $error = captureThrows(
                fn() => $this->tools($pdo, ['/nonexistent-allowed-root'])->call('move_project', ['from' => self::OLD_ROOT, 'to' => $newRoot, 'execute' => true]),
                InvalidArgumentException::class,
            );

            self::assertStringStartsWith('MOVE_TARGET_NOT_ALLOWED:', $error->getMessage());
            self::assertStringContainsString('knossos allow-root', $error->getMessage());
            self::assertSame(1, $this->annotationCount($pdo, $oldId));
        } finally {
            $this->removeTempTree($newRoot);
        }
    }

    /** A relative target would resolve against the server's working directory, which the caller cannot see. */
    #[Group('mcp')]
    public function testARelativeTargetIsRefused(): void
    {
        [$pdo, , $newRoot] = $this->movedCheckout();
        try {
            $error = captureThrows(
                fn() => $this->tools($pdo, null)->call('move_project', ['from' => self::OLD_ROOT, 'to' => 'project']),
                InvalidArgumentException::class,
            );

            self::assertStringStartsWith('MOVE_TARGET_NOT_ABSOLUTE:', $error->getMessage());
            self::assertStringNotContainsString('allow-root', $error->getMessage(), 'Granting a relative path would grant nothing.');
        } finally {
            $this->removeTempTree($newRoot);
        }
    }

    /**
     * @return array{0: PDO, 1: string, 2: string}
     */
    private function movedCheckout(): array
    {
        $pdo = $this->freshTestDatabase();
        $oldId = StableId::project('root:' . self::OLD_ROOT);
        (new SqliteGraphRepository($pdo))->saveProject($oldId, 'project', self::OLD_ROOT);
        $pdo->prepare(
            "INSERT INTO annotations(project_id, canonical_name, kind, value, author, created_at, updated_at) " .
            "VALUES (:project, 'App\\A', 'note', 'v', 'model', '2026-10-01T00:00:00Z', '2026-10-01T00:00:00Z')",
        )->execute(['project' => $oldId]);
        $newRoot = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($newRoot . '/src', 0o700, true);
        file_put_contents($newRoot . '/src/A.php', "<?php\nnamespace App;\nfinal class A {}\n");

        return [$pdo, $oldId, $newRoot];
    }

    /**
     * @param list<string>|null $roots the allowed roots, or null for a ToolService with no environment
     */
    private function tools(PDO $pdo, ?array $roots): ToolService
    {
        return new ToolService(
            new ProjectScanService($pdo, self::repositoryRoot(), $roots ?? ['/']),
            ArchitectureQueryService::forDatabase($pdo),
            new DatabaseMaintenanceService($pdo, ':memory:'),
            new ResultEnricher(new StalenessProbe($pdo), new NextStepPlanner()),
            $roots === null ? null : new ServerEnvironment(new AllowedRoots($roots), ':memory:', self::repositoryRoot(), $pdo),
        );
    }

    private function annotationCount(PDO $pdo, string $projectId): int
    {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM annotations WHERE project_id = :project');
        $statement->execute(['project' => $projectId]);

        return (int) $statement->fetchColumn();
    }
}
