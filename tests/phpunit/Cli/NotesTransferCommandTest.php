<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Cli;

use InvalidArgumentException;
use Knossos\Cli\CliCommandContext;
use Knossos\Cli\CliInputLoader;
use Knossos\Cli\CliOptionParser;
use Knossos\Cli\Command\MaintenanceCommand;
use Knossos\Notes\NotesFile;
use Knossos\Runtime\RuntimeFactory;
use Knossos\Scan\ProjectScanService;
use Knossos\Scan\ScanOptions;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/** The shell form of exporting and importing a project's notes. */
final class NotesTransferCommandTest extends KnossosTestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/knossos-stale-notes-cli-' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeTempTree($this->tempDir);
        parent::tearDown();
    }

    private function checkout(string $name): string
    {
        $root = $this->tempDir . '/' . $name;
        mkdir($root . '/src', 0o755, true);
        file_put_contents($root . '/composer.json', '{"name":"app/app","autoload":{"psr-4":{"App\\\\":"src/"}}}');
        file_put_contents($root . '/src/A.php', "<?php\nnamespace App;\nfinal class A {}\n");

        return (string) realpath($root);
    }

    private function note(CliCommandContext $context, string $projectId): void
    {
        $context->database()->prepare(
            'INSERT INTO annotations(project_id, canonical_name, kind, value, author, basis_json, created_at, updated_at) ' .
            "VALUES (:p, 'App\\A', 'note', 'v', 'user', '[]', '2026-10-01T00:00:00Z', '2026-10-01T00:00:00Z')",
        )->execute(['p' => $projectId]);
    }

    private function noteCount(CliCommandContext $context, string $projectId): int
    {
        $statement = $context->database()->prepare('SELECT COUNT(*) FROM annotations WHERE project_id = ?');
        $statement->execute([$projectId]);

        return (int) $statement->fetchColumn();
    }

    /** @param list<string> $positionals @param array<string, list<string>> $options */
    private function cli(string $command, array $positionals, array $options, CliCommandContext $context): string
    {
        ob_start();
        try {
            (new MaintenanceCommand())->run($command, $positionals, $options + ['json' => ['true']], $context);

            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    /** A notes file carried to a never-scanned checkout is previewed after a scan, then imported. */
    #[Group('cli')]
    public function testExportThenImportIntoSecondCheckout(): void
    {
        $context = $this->context();
        $a = $this->checkout('a');
        $b = $this->checkout('b');
        $idA = (new ProjectScanService($context->database(), self::repositoryRoot(), [$a]))->scan($a, new ScanOptions(mode: 'full'))->projectId;
        $this->note($context, $idA);

        $exported = json_decode($this->cli('export-notes', [$a], [], $context), true);
        self::assertIsArray($exported);
        self::assertSame(1, $exported['data']['total']);
        copy($a . '/' . NotesFile::DEFAULT_NAME, $b . '/' . NotesFile::DEFAULT_NAME);

        $idB = ProjectScanService::projectIdOf($b);
        $dry = json_decode($this->cli('import-notes', [$b], [], $context), true);
        self::assertIsArray($dry);
        self::assertTrue($dry['data']['scanned']);
        self::assertFalse($dry['data']['executed']);
        self::assertStringStartsWith('Dry run: 1 notes would be imported', $dry['summary']);
        self::assertSame(0, $this->noteCount($context, $idB));

        $done = json_decode($this->cli('import-notes', [$b], ['execute' => ['true']], $context), true);
        self::assertIsArray($done);
        self::assertTrue($done['data']['executed']);
        self::assertSame(1, $this->noteCount($context, $idB));
    }

    /** Exporting a root nothing has scanned is refused. */
    #[Group('cli')]
    public function testExportOfUnscannedRootIsRefused(): void
    {
        $root = $this->checkout('fresh');
        $error = captureThrows(fn() => $this->cli('export-notes', [$root], [], $this->context()), InvalidArgumentException::class);

        self::assertStringContainsString('NOTES_PROJECT_NOT_SCANNED', $error->getMessage());
    }

    /** Without --file the export lands in the checkout's root. */
    #[Group('cli')]
    public function testDefaultFileIsInTheRoot(): void
    {
        $context = $this->context();
        $a = $this->checkout('a');
        (new ProjectScanService($context->database(), self::repositoryRoot(), [$a]))->scan($a, new ScanOptions(mode: 'full'));
        $this->cli('export-notes', [$a], [], $context);

        self::assertFileExists($a . '/' . NotesFile::DEFAULT_NAME);
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
