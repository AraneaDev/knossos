<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Mcp;

use InvalidArgumentException;
use Knossos\Maintenance\DatabaseMaintenanceService;
use Knossos\Mcp\{NextStepPlanner, ResultEnricher, ToolService};
use Knossos\Query\{ArchitectureQueryService, StalenessProbe};
use Knossos\Roots\AllowedRoots;
use Knossos\Runtime\ServerEnvironment;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * The tool form of notes export and import: a round trip through both, the
 * default file in the root, and the three refusals that keep a caller inside
 * the roots the server may read.
 */
final class NotesTransferToolTest extends KnossosTestCase
{
    /** Export then import through the tools restores the note, using the default file in the root. */
    #[Group('mcp')]
    public function testRoundTripThroughTheDefaultFile(): void
    {
        $root = $this->checkout();
        try {
            $pdo = $this->freshTestDatabase();
            $tools = $this->tools($pdo, [$root]);
            $tools->call('scan_project', ['path' => $root]);
            $pdo->exec("INSERT INTO annotations(project_id, canonical_name, kind, value, author, created_at, updated_at) SELECT id, 'App\\A', 'note', 'v', 'model', '2026-10-01T00:00:00Z', '2026-10-01T00:00:00Z' FROM projects");

            $exported = $tools->call('export_notes', ['path' => $root]);
            self::assertFileExists($root . '/knossos-notes.json');
            self::assertSame(1, $exported->data['total'] ?? null);

            $pdo->exec('DELETE FROM annotations');
            $preview = $tools->call('import_notes', ['path' => $root]);
            self::assertFalse($preview->data['executed']);
            self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM annotations')->fetchColumn());

            $done = $tools->call('import_notes', ['path' => $root, 'execute' => true]);
            self::assertTrue($done->data['executed']);
            self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM annotations')->fetchColumn());
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A relative path or file is refused before anything is read. */
    #[Group('mcp')]
    public function testRelativePathsAreRefused(): void
    {
        $root = $this->checkout();
        try {
            $tools = $this->tools($this->freshTestDatabase(), [$root]);
            foreach ([['path' => 'project'], ['path' => $root, 'file' => 'notes.json']] as $arguments) {
                $error = captureThrows(fn() => $tools->call('export_notes', $arguments), InvalidArgumentException::class);
                self::assertStringStartsWith('NOTES_PATH_NOT_ABSOLUTE:', $error->getMessage());
            }
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A path outside the allowed roots is refused with the command that grants it. */
    #[Group('mcp')]
    public function testARootOutsideTheAllowedRootsIsRefused(): void
    {
        $root = $this->checkout();
        try {
            $tools = $this->tools($this->freshTestDatabase(), ['/nonexistent-allowed-root']);
            foreach (['export_notes', 'import_notes'] as $tool) {
                $error = captureThrows(fn() => $tools->call($tool, ['path' => $root]), InvalidArgumentException::class);
                self::assertStringStartsWith('NOTES_ROOT_NOT_ALLOWED:', $error->getMessage());
                self::assertStringContainsString('knossos allow-root ' . $root . ' --execute', $error->getMessage());
            }
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A file whose directory lies outside the project is refused, so a caller cannot write anywhere the server can. */
    #[Group('mcp')]
    public function testAFileOutsideTheRootIsRefused(): void
    {
        $root = $this->checkout();
        $other = $this->checkout();
        try {
            $tools = $this->tools($this->freshTestDatabase(), [$root]);
            foreach (['export_notes', 'import_notes'] as $tool) {
                $error = captureThrows(fn() => $tools->call($tool, ['path' => $root, 'file' => $other . '/n.json']), InvalidArgumentException::class);
                self::assertStringStartsWith('NOTES_FILE_OUTSIDE_ROOT:', $error->getMessage());
            }
            self::assertFileDoesNotExist($other . '/n.json');
        } finally {
            $this->removeTempTree($root);
            $this->removeTempTree($other);
        }
    }

    private function checkout(): string
    {
        $root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($root . '/src', 0o700, true);
        file_put_contents($root . '/src/A.php', "<?php\nnamespace App;\nfinal class A {}\n");

        return (string) realpath($root);
    }

    /**
     * @param list<string> $roots the allowed roots
     */
    private function tools(PDO $pdo, array $roots): ToolService
    {
        return new ToolService(
            new ProjectScanService($pdo, self::repositoryRoot(), ['/']),
            ArchitectureQueryService::forDatabase($pdo),
            new DatabaseMaintenanceService($pdo, ':memory:'),
            new ResultEnricher(new StalenessProbe($pdo), new NextStepPlanner()),
            new ServerEnvironment(new AllowedRoots($roots), ':memory:', self::repositoryRoot(), $pdo),
        );
    }

    /** A file that does not end in .json is refused, and nothing is written. */
    #[Group('mcp')]
    public function testAFileThatIsNotJsonIsRefused(): void
    {
        $root = $this->checkout();
        try {
            $tools = $this->tools($this->freshTestDatabase(), [$root]);
            foreach (['export_notes', 'import_notes'] as $tool) {
                $error = captureThrows(fn() => $tools->call($tool, ['path' => $root, 'file' => $root . '/src/A.php']), InvalidArgumentException::class);
                self::assertStringStartsWith('NOTES_FILE_NOT_JSON:', $error->getMessage());
            }
            self::assertStringContainsString('final class A', (string) file_get_contents($root . '/src/A.php'));
        } finally {
            $this->removeTempTree($root);
        }
    }
}
