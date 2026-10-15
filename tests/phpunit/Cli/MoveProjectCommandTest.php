<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Cli;

use InvalidArgumentException;
use Knossos\Cli\CliCommandContext;
use Knossos\Cli\CliInputLoader;
use Knossos\Cli\CliOptionParser;
use Knossos\Cli\Command\MaintenanceCommand;
use Knossos\Discovery\AllowedRoots;
use Knossos\Discovery\RootsFile;
use Knossos\Runtime\RuntimeFactory;
use Knossos\Scan\ProjectScanService;
use Knossos\Store\SqliteGraphRepository;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/** The shell form of a move, the one that may also update the roots file. */
final class MoveProjectCommandTest extends KnossosTestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeTempTree($this->tempDir);
        parent::tearDown();
    }

    /** Execute moves the note and swaps the grant in the roots file the database uses. */
    #[Group('cli')]
    public function testExecuteMovesNotesAndSwapsTheGrant(): void
    {
        $context = $this->context();
        $pdo = $context->database();
        $oldRoot = '/nonexistent/knossos-move-cli/project';
        $oldId = StableId::project('root:' . $oldRoot);
        (new SqliteGraphRepository($pdo))->saveProject($oldId, 'project', $oldRoot);
        $pdo->prepare(
            "INSERT INTO annotations(project_id, canonical_name, kind, value, author, created_at, updated_at) " .
            "VALUES (:project, 'App\\A', 'note', 'v', 'model', '2026-10-01T00:00:00Z', '2026-10-01T00:00:00Z')",
        )->execute(['project' => $oldId]);
        $newRoot = $this->tempDir . '/project';
        mkdir($newRoot . '/src', 0o700, true);
        file_put_contents($newRoot . '/src/A.php', "<?php\nnamespace App;\nfinal class A {}\n");
        $rootsPath = AllowedRoots::defaultConfigPath($context->databasePath());
        (new RootsFile($rootsPath))->write([$oldRoot]);

        ob_start();
        $exit = (new MaintenanceCommand())->run('move-project', [$oldRoot, $newRoot], ['execute' => ['true'], 'json' => ['true']], $context);
        ob_end_clean();

        self::assertSame(0, $exit);
        $statement = $pdo->prepare('SELECT COUNT(*) FROM annotations WHERE project_id = :project');
        $statement->execute(['project' => ProjectScanService::projectIdOf((string) realpath($newRoot))]);
        self::assertSame(1, (int) $statement->fetchColumn());
        self::assertSame([(string) realpath($newRoot)], (new RootsFile($rootsPath))->read());
    }

    /** Without --execute nothing is written, the roots file included. */
    #[Group('cli')]
    public function testThePreviewLeavesTheRootsFileAlone(): void
    {
        $context = $this->context();
        $oldRoot = '/nonexistent/knossos-move-cli/project';
        (new SqliteGraphRepository($context->database()))->saveProject(StableId::project('root:' . $oldRoot), 'project', $oldRoot);
        $rootsPath = AllowedRoots::defaultConfigPath($context->databasePath());
        (new RootsFile($rootsPath))->write([$oldRoot]);

        ob_start();
        (new MaintenanceCommand())->run('move-project', [$oldRoot, $this->tempDir], [], $context);
        $output = (string) ob_get_clean();

        self::assertStringContainsString('Dry run', $output);
        self::assertSame([$oldRoot], (new RootsFile($rootsPath))->read());
    }

    /** Two positionals are required; one is a usage error. */
    #[Group('cli')]
    public function testOneArgumentIsAUsageError(): void
    {
        $error = captureThrows(fn() => (new MaintenanceCommand())->run('move-project', ['/x'], [], $this->context()), InvalidArgumentException::class);

        self::assertStringContainsString('Usage: knossos move-project', $error->getMessage());
    }

    private function context(): CliCommandContext
    {
        return new CliCommandContext(
            new CliOptionParser(),
            new CliInputLoader(),
            new RuntimeFactory(self::repositoryRoot()),
            $this->tempDir . '/knossos.sqlite',
        );
    }
}
