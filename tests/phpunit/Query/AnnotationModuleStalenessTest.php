<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\AnnotationNotes;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Scan\ProjectScanService;
use Knossos\Scan\ScanOptions;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\Support\WorkerClients;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * A model note on a module stands for the whole file the module is: a worker
 * gives a file's module node the first line only, so a span would rest the
 * note on that line and leave it fresh after any edit below it. Such a note
 * records no span and goes stale when its file changes, in every language
 * whose worker emits module nodes, through the real workers and real scans.
 */
#[Group('query')]
final class AnnotationModuleStalenessTest extends KnossosTestCase
{
    use WorkerClients;

    private string $root;

    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/knossos-stale-module-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o777, true);
        $this->pdo = $this->freshTestDatabase();
    }

    protected function tearDown(): void
    {
        $this->removeTempTree($this->root);
        parent::tearDown();
    }

    /** @return iterable<string, array{0: array<string, string>, 1: string, 2: string, 3: string}> */
    public static function modules(): iterable
    {
        yield 'php script' => [
            ['composer.json' => '{"name":"app/app","autoload":{"psr-4":{"App\\\\":"src/"}}}'],
            'tools/report.php',
            "<?php\n\nprintf('report');\n",
            "<?php\n\nsystem('rm -rf /tmp/x');\nprintf('report');\n",
        ];
        yield 'python module' => [
            ['pyproject.toml' => "[project]\nname = \"app\"\n"],
            'app/report.py',
            "import os\n\nprint('report')\n",
            "import os\n\nos.system('rm -rf /tmp/x')\nprint('report')\n",
        ];
        yield 'rust module' => [
            ['Cargo.toml' => "[package]\nname = \"demo\"\nversion = \"0.1.0\"\n", 'src/lib.rs' => "pub mod util;\n"],
            'src/util.rs',
            "use std::fs;\n\npub fn helper() -> u32 {\n    1\n}\n",
            "use std::fs;\n\npub fn helper() -> u32 {\n    2\n}\n",
        ];
        yield 'typescript module' => [
            ['package.json' => '{"name":"app","type":"module"}', 'tsconfig.json' => '{"compilerOptions":{"strict":true},"include":["src"]}'],
            'src/report.ts',
            "import { readFileSync } from \"node:fs\";\n\nexport const size = 1;\n",
            "import { readFileSync } from \"node:fs\";\n\nexport const size = readFileSync(\"/etc/passwd\").length;\n",
        ];
    }

    /** @param array<string, string> $project */
    #[DataProvider('modules')]
    public function testANoteOnAModuleRecordsNoSpanAndGoesStaleOnAnEditBelowItsFirstLine(array $project, string $path, string $source, string $edited): void
    {
        $this->skipWithoutWorker($path);
        foreach ($project as $file => $contents) {
            $this->write($file, $contents);
        }
        $this->write($path, $source);
        $projectId = $this->rescan();
        $module = $this->moduleIn($path);
        ArchitectureQueryService::forDatabase($this->pdo)->services()->annotationQueries
            ->upsertAnnotation($projectId, $module, 'note', 'prints the report', execute: true);
        $basis = json_decode((string) $this->pdo->query('SELECT basis_json FROM annotations')->fetchColumn(), true);
        self::assertSame([['path' => $path, 'hash' => hash('sha256', $source)]], $basis);

        $this->write($path, $edited);
        $this->rescan();

        $note = (new AnnotationNotes($this->pdo))->forComponents($projectId, [$module])[0];
        self::assertSame([true, sprintf('written before %s changed', $path)], [$note['stale'], $note['stale_reason']]);
    }

    /** A span recorded on a module before modules recorded none is not trusted: the whole file decides. */
    public function testASpanAlreadyRecordedOnAModuleIsNotTrusted(): void
    {
        [$project, $path, $source, $edited] = iterator_to_array(self::modules())['python module'];
        foreach ($project as $file => $contents) {
            $this->write($file, $contents);
        }
        $this->write($path, $source);
        $projectId = $this->rescan();
        $module = $this->moduleIn($path);
        ArchitectureQueryService::forDatabase($this->pdo)->services()->annotationQueries
            ->upsertAnnotation($projectId, $module, 'note', 'prints the report', execute: true);
        $firstLine = ['start' => 1, 'end' => 1, 'hash' => hash('sha256', 'import os')];
        $this->pdo->prepare('UPDATE annotations SET basis_json = ?')
            ->execute([json_encode([['path' => $path, 'hash' => hash('sha256', $source), 'span' => $firstLine]])]);

        $this->write($path, $edited);
        $this->rescan();

        $note = (new AnnotationNotes($this->pdo))->forComponents($projectId, [$module])[0];
        self::assertTrue($note['stale']);
    }

    private function skipWithoutWorker(string $path): void
    {
        if (str_ends_with($path, '.rs') && !is_file(self::rustWorkerBinary())) {
            self::markTestSkipped('The Rust worker binary is not built.');
        }
    }

    /** The canonical name of the module node the scan gave `$path`. */
    private function moduleIn(string $path): string
    {
        $statement = $this->pdo->prepare("SELECT n.canonical_name FROM nodes n JOIN files f ON f.id = n.file_id WHERE n.kind = 'module' AND f.relative_path = ?");
        $statement->execute([$path]);
        $names = $statement->fetchAll(PDO::FETCH_COLUMN);
        self::assertCount(1, $names, 'one module node for ' . $path);

        return (string) $names[0];
    }

    private function write(string $relative, string $contents): void
    {
        $path = $this->root . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o777, true);
        }
        file_put_contents($path, $contents);
    }

    private function rescan(): string
    {
        return (new ProjectScanService($this->pdo, self::repositoryRoot(), [$this->root]))->scan($this->root, new ScanOptions(mode: 'full'))->projectId;
    }
}
