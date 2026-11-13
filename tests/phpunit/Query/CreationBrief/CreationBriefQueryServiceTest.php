<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query\CreationBrief;

use InvalidArgumentException;
use Knossos\Query\CreationBrief\CreationBriefQueryService;
use Knossos\Scan\Conventions\FolderFactsBuilder;
use Knossos\Scan\ProjectScanService;
use Knossos\Store\FolderFactsStore;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\Support\SyntheticGraph;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/** What a file about to be created should look like, from a scanned tree that has a folder of services and their tests. */
#[Group('query')]
final class CreationBriefQueryServiceTest extends KnossosTestCase
{
    private const SERVICES = ['A', 'B', 'C', 'D', 'E', 'F'];

    private string $root = '';

    /** @var list<string> every tree a test made, so a test that scans twice leaves none behind */
    private array $roots = [];

    private PDO $pdo;

    private string $projectId = '';

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            $this->removeTempTree($root);
        }
        parent::tearDown();
    }

    /** Scan a tree of six services and tests, a knossos.json with a boundary, a deny policy and two gates. */
    private function scanned(bool $withConfiguration = true): CreationBriefQueryService
    {
        $this->root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        $this->roots[] = $this->root;
        foreach (self::SERVICES as $name) {
            $this->service($name);
        }
        mkdir($this->root . '/src/Mcp', 0o777, true);
        file_put_contents($this->root . '/src/Mcp/Tool.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Mcp;\n\nfinal class Tool {}\n");
        if ($withConfiguration) {
            $this->config([['name' => 'cs', 'command' => 'vendor/bin/php-cs-fixer fix'], ['name' => 'docs', 'command' => 'npx prettier --check', 'applies_to' => ['docs/*']]]);
        }

        $this->pdo = $this->freshTestDatabase();
        $this->projectId = $this->scan()->projectId;

        return new CreationBriefQueryService($this->pdo);
    }

    private function scan(): \Knossos\Result\ResultEnvelope
    {
        return (new ProjectScanService($this->pdo, self::repositoryRoot(), [$this->root]))->scan($this->root);
    }

    private function service(string $name): void
    {
        foreach (['src/Query', 'tests/phpunit/Query'] as $directory) {
            if (!is_dir($this->root . '/' . $directory)) {
                mkdir($this->root . '/' . $directory, 0o777, true);
            }
        }
        file_put_contents($this->root . "/src/Query/{$name}Service.php", "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Query;\n\nfinal readonly class {$name}Service\n{\n    public function __construct(private \\PDO \$pdo) {}\n\n    public function run(): void {}\n}\n");
        file_put_contents($this->root . "/tests/phpunit/Query/{$name}ServiceTest.php", "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Tests\\Query;\n\nuse App\\Query\\{$name}Service;\n\nfinal class {$name}ServiceTest\n{\n    public function testIt(): void\n    {\n        (new {$name}Service(new \\PDO('sqlite::memory:')))->run();\n    }\n}\n");
    }

    /** @param list<array<string, mixed>> $gates */
    private function config(array $gates): void
    {
        file_put_contents($this->root . '/knossos.json', json_encode([
            'version' => 1,
            'boundaries' => [['name' => 'Query', 'path_prefix' => 'src/Query/'], ['name' => 'Mcp', 'path_prefix' => 'src/Mcp/']],
            'policies' => [['id' => 'query-not-mcp', 'from_boundary' => 'Query', 'deny_targets' => ['Mcp']]],
            'gates' => $gates,
        ], JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $data */
    private static function ids(array $data): array
    {
        return array_column($data['conventions'], 'id');
    }

    public function testANewServiceIsBriefedFromItsFolder(): void
    {
        $data = $this->scanned()->creationBrief($this->projectId, 'src/Query/FooService.php')->data;

        self::assertFalse($data['exists']);
        self::assertSame('source', $data['role']);
        self::assertSame(['directory' => 'src/Query', 'files' => 6, 'borrowed' => false], $data['basis']);
        foreach (['kind', 'modifier:final', 'modifier:readonly', 'suffix'] as $id) {
            self::assertContains($id, self::ids($data));
        }
        self::assertSame(['path' => 'tests/phpunit/Query/FooServiceTest.php', 'kind' => 'test'], $data['goes_with'][0]);
        self::assertSame(['cs'], array_column($data['gates'], 'name'));
        self::assertSame(['boundary' => 'Query', 'may_not_use' => ['Mcp']], $data['placement']);
        self::assertSame(['src/Query/AService.php', 'src/Query/BService.php', 'src/Query/CService.php'], $data['neighbours']);
        self::assertSame('New file in Query: ' . count($data['conventions']) . ' conventions, 1 gates.', $this->scanned()->creationBrief($this->projectId, 'src/Query/FooService.php')->summary);
    }

    public function testANewFolderBorrowsFromTheNearestPopulatedAncestor(): void
    {
        $data = $this->scanned()->creationBrief($this->projectId, 'src/Query/Deep/New/X.php')->data;

        self::assertSame('src/Query', $data['basis']['directory']);
        self::assertTrue($data['basis']['borrowed']);
        self::assertNotSame([], $data['conventions']);
    }

    public function testWithNoPopulatedAncestorOnlyPlacementAndGatesRemain(): void
    {
        $data = $this->scanned()->creationBrief($this->projectId, 'lib/X.php')->data;

        self::assertNull($data['basis']);
        self::assertSame([], $data['neighbours']);
        self::assertSame([], $data['conventions']);
        self::assertSame(['cs'], array_column($data['gates'], 'name'));
    }

    public function testAFileNoScannerCoversGetsGatesAndCompanionsOnly(): void
    {
        $data = $this->scanned()->creationBrief($this->projectId, 'docs/x.md')->data;

        self::assertSame(['cs', 'docs'], array_column($data['gates'], 'name'));
        self::assertSame([], $data['conventions']);
        self::assertSame('source', $data['role']);
    }

    public function testATestFileIsBriefedFromTheTestsBesideIt(): void
    {
        $data = $this->scanned()->creationBrief($this->projectId, 'tests/phpunit/Query/FooServiceTest.php')->data;

        self::assertSame('test', $data['role']);
        self::assertSame('tests/phpunit/Query', $data['basis']['directory']);
        self::assertNotContains('test', array_column($data['goes_with'], 'kind'));
    }

    /** A test in a folder that holds no files yet is a test when the project's source folders name tests that way. */
    public function testANewTestFolderIsRecognisedByTheNameTestsHaveElsewhere(): void
    {
        $service = $this->scanned();
        // A source folder elsewhere whose files all have a sibling `.spec.php`, as a `<dir>/` pattern says it.
        $this->pdo->prepare('INSERT INTO folder_facts (project_id, directory, extension, role, files, facts_json) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$this->projectId, 'lib/old', 'php', 'source', 5, json_encode(['test_path' => ['pattern' => '<dir>/<name>.spec.php', 'files' => 5]], JSON_THROW_ON_ERROR)]);

        $spec = $service->creationBrief($this->projectId, 'lib/new/Thing.spec.php')->data;
        $plain = $service->creationBrief($this->projectId, 'lib/new/Thing.php')->data;
        $root = $service->creationBrief($this->projectId, 'Thing.spec.php')->data;

        self::assertSame('test', $spec['role']);
        self::assertSame('source', $plain['role'], 'a name that does not have the shape is a source file');
        self::assertSame('test', $root['role'], 'a directory hole matches the root too');
    }

    /**
     * Nested declared boundaries both bind a file: the brief names the inner one
     * and forbids what either forbids.
     */
    public function testANestedBoundaryForbidsWhatEveryEnclosingBoundaryForbids(): void
    {
        $service = $this->scanned();
        mkdir($this->root . '/tools', 0o777, true);
        file_put_contents($this->root . '/tools/Lint.php', "<?php\n\nfinal class Lint {}\n");
        file_put_contents($this->root . '/knossos.json', json_encode([
            'version' => 1,
            'boundaries' => [
                ['name' => 'core', 'path_prefix' => 'src/'],
                ['name' => 'Query', 'path_prefix' => 'src/Query/'],
                ['name' => 'Mcp', 'path_prefix' => 'src/Mcp/'],
                ['name' => 'tooling', 'path_prefix' => 'tools/'],
            ],
            'policies' => [
                ['id' => 'query-not-mcp', 'from_boundary' => 'Query', 'deny_targets' => ['Mcp']],
                ['id' => 'core-not-tooling', 'from_boundary' => 'core', 'deny_targets' => ['tooling']],
            ],
        ], JSON_THROW_ON_ERROR));
        $this->scan();

        self::assertSame(['boundary' => 'Query', 'may_not_use' => ['Mcp', 'tooling']], $service->creationBrief($this->projectId, 'src/Query/FooService.php')->data['placement']);
        self::assertSame(['boundary' => 'core', 'may_not_use' => ['tooling']], $service->creationBrief($this->projectId, 'src/Other/Foo.php')->data['placement']);
    }

    /**
     * A test in a new folder under a folder that holds only tests is a test, briefed
     * from that folder, even though no source folder's test pattern names its path.
     */
    public function testANewTestFolderUnderATestOnlyFolderIsATest(): void
    {
        $this->scanned();
        foreach (['P', 'Q', 'R', 'S', 'T'] as $name) {
            file_put_contents($this->root . "/tests/phpunit/{$name}Test.php", "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Tests;\n\nfinal class {$name}Test\n{\n    public function testIt(): void {}\n}\n");
        }
        $this->scan();

        $data = (new CreationBriefQueryService($this->pdo))->creationBrief($this->projectId, 'tests/phpunit/NewDir/FooTest.php')->data;

        self::assertSame('test', $data['role']);
        self::assertSame(['directory' => 'tests/phpunit', 'files' => 5, 'borrowed' => true], $data['basis']);
    }

    /**
     * A literal test folder borrowed from an ancestor would name a test beside the
     * ancestor's tests, which is a guess; a `<dir>` pattern says where it goes and stays.
     */
    public function testABorrowedLiteralTestPatternNamesNoTest(): void
    {
        $service = $this->scanned();

        $literal = $service->creationBrief($this->projectId, 'src/Query/Deep/New/X.php')->data;
        $this->pdo->prepare("UPDATE folder_facts SET facts_json = json_set(facts_json, '$.test_path', json(?)) WHERE project_id = ? AND directory = 'src/Query' AND extension = 'php' AND role = 'source'")
            ->execute([json_encode(['pattern' => '<dir>/<name>.spec.php', 'files' => 6], JSON_THROW_ON_ERROR), $this->projectId]);
        $relative = $service->creationBrief($this->projectId, 'src/Query/Deep/New/X.php')->data;

        self::assertTrue($literal['basis']['borrowed']);
        self::assertNotContains('test', array_column($literal['goes_with'], 'kind'));
        self::assertSame(['path' => 'src/Query/Deep/New/X.spec.php', 'kind' => 'test'], $relative['goes_with'][0] ?? null);
    }

    /** A one-file folder's test pattern is below the floor, so it makes no path a test. */
    public function testAOneFileFoldersPatternDoesNotMakeATest(): void
    {
        $service = $this->scanned();
        $this->pdo->prepare('INSERT INTO folder_facts (project_id, directory, extension, role, files, facts_json) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$this->projectId, 'lib/old', 'php', 'source', 1, json_encode(['test_path' => ['pattern' => '<dir>/<name>.spec.php', 'files' => 1]], JSON_THROW_ON_ERROR)]);

        self::assertSame('source', $service->creationBrief($this->projectId, 'lib/new/Thing.spec.php')->data['role']);
    }

    /** A companion whose file has since been deleted is not named. */
    public function testADeletedCompanionIsLeftOut(): void
    {
        $service = $this->scanned();
        (new FolderFactsStore($this->pdo))->saveCompanions($this->projectId, 'head', 'ok', ['src/Query' => [
            ['path' => 'tools/gone.txt', 'commits' => 6, 'of' => 6],
            ['path' => 'knossos.json', 'commits' => 5, 'of' => 6],
        ]]);

        $goesWith = $service->creationBrief($this->projectId, 'src/Query/FooService.php')->data['goes_with'];

        self::assertSame(['tests/phpunit/Query/FooServiceTest.php', 'knossos.json'], array_column($goesWith, 'path'));
    }

    /** The root itself, however it is spelt, is a directory and gets no brief. */
    public function testThePathOfTheRootIsRefusedAsADirectory(): void
    {
        $service = $this->scanned();
        $root = (string) realpath($this->root);

        foreach (['.', './', './.', 'src/..', 'src/Query/../..', $root, $root . '/', $root . '/.', 'src/Query/.'] as $path) {
            try {
                $service->creationBrief($this->projectId, $path);
                self::fail($path . ' was briefed.');
            } catch (InvalidArgumentException $error) {
                self::assertStringContainsString('names a directory', $error->getMessage(), $path);
            }
        }
        $this->expectExceptionMessage('is outside the project');
        $service->creationBrief($this->projectId, 'src/../../x.php');
    }

    public function testAnExistingPathSaysSo(): void
    {
        $data = $this->scanned()->creationBrief($this->projectId, 'src/Query/AService.php')->data;

        self::assertTrue($data['exists']);
        self::assertSame('src/Query', $data['basis']['directory']);
        self::assertSame('source', $data['role']);
    }

    public function testPathsAreNormalisedAndConfined(): void
    {
        $service = $this->scanned();
        $root = (string) realpath($this->root);

        self::assertSame('src/Query/A B.php', $service->creationBrief($this->projectId, $root . '/src/Query/A B.php')->data['path']);
        self::assertSame('src/Query/123', $service->creationBrief($this->projectId, './src/Query/123')->data['path']);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is outside the project');
        $service->creationBrief($this->projectId, '../x.php');
    }

    public function testTheSignatureIgnoresCountsAndNeighbours(): void
    {
        $service = $this->scanned();
        $before = $service->creationBrief($this->projectId, 'src/Query/FooService.php')->data['signature'];

        $this->service('G');
        $this->scan();
        $after = $service->creationBrief($this->projectId, 'src/Query/FooService.php')->data;
        self::assertSame(7, $after['basis']['files']);
        self::assertSame($before, $after['signature']);

        $this->config([['name' => 'cs', 'command' => 'vendor/bin/php-cs-fixer fix'], ['name' => 'lint', 'command' => 'make lint']]);
        self::assertNotSame($before, $service->creationBrief($this->projectId, 'src/Query/FooService.php')->data['signature']);
    }

    /** A knossos.json that cannot be read costs the brief its placement and gates, not its answer. */
    public function testAnUnreadableConfigurationIsAWarningNotAFailure(): void
    {
        $service = $this->scanned();
        file_put_contents($this->root . '/knossos.json', '{ not json');

        $result = $service->creationBrief($this->projectId, 'src/Query/FooService.php');

        self::assertSame([], $result->data['gates']);
        self::assertSame(['boundary' => null, 'may_not_use' => []], $result->data['placement']);
        self::assertNotSame([], $result->data['conventions']);
        self::assertCount(1, $result->warnings);
        self::assertStringContainsString('knossos.json could not be read', $result->warnings[0]);
    }

    public function testAnAbsolutePathOutsideTheRootAndADirectoryAreRefused(): void
    {
        $service = $this->scanned();

        foreach (['/etc/passwd' => 'is outside the project', 'src/Query/' => 'names a directory'] as $path => $message) {
            try {
                $service->creationBrief($this->projectId, (string) $path);
                self::fail($path . ' was briefed.');
            } catch (InvalidArgumentException $error) {
                self::assertStringContainsString($message, $error->getMessage());
            }
        }
    }

    /** A file with no extension, and one at the root, are briefed from what their folder holds, without error. */
    public function testAFileWithoutAnExtensionAndAFileAtTheRootAnswer(): void
    {
        $service = $this->scanned();

        $makefile = $service->creationBrief($this->projectId, 'src/Query/Makefile')->data;
        $readme = $service->creationBrief($this->projectId, 'README')->data;

        self::assertSame(['', 'src/Query', null], [$makefile['extension'], $makefile['directory'], $makefile['basis']]);
        self::assertSame(['', '', null], [$readme['extension'], $readme['directory'], $readme['basis']]);
        self::assertSame(['cs'], array_column($readme['gates'], 'name'));
    }

    /** A project with no knossos.json has nothing declared (scanned without one): empty placement and gates, and no warning. */
    public function testAbsentConfigurationIsNotAnError(): void
    {
        $service = $this->scanned(withConfiguration: false);

        $result = $service->creationBrief($this->projectId, 'src/Query/FooService.php');

        self::assertSame(['boundary' => null, 'may_not_use' => []], $result->data['placement']);
        self::assertSame([], $result->data['gates']);
        self::assertSame([], $result->warnings);
        self::assertNotSame([], $result->data['conventions']);
    }

    /** On the 20,000-node synthetic graph the brief is a few indexed reads. */
    public function testTheBriefOnALargeGraphIsAnsweredQuickly(): void
    {
        $this->root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        $this->roots[] = $this->root;
        mkdir($this->root . '/src', 0o777, true);
        [$this->pdo, , $ids] = $this->storeFixture();
        $this->projectId = $ids['project'];
        $this->pdo->prepare('DELETE FROM files WHERE project_id = ?')->execute([$this->projectId]);
        $this->pdo->prepare('DELETE FROM nodes WHERE project_id = ?')->execute([$this->projectId]);
        $this->pdo->prepare('INSERT INTO files (id, project_id, relative_path, content_hash, size, mtime, language, scanner_version, last_scan_id, line_count) VALUES (?, ?, ?, ?, 1, 1, ?, ?, ?, 9)')
            ->execute([$ids['file'], $this->projectId, 'src/Checkout.php', 'h', 'php', '1', $ids['scan']]);
        SyntheticGraph::seed($this->pdo, $ids, 20000, 4);
        $insert = $this->pdo->prepare('INSERT INTO files (id, project_id, relative_path, content_hash, size, mtime, language, scanner_version, last_scan_id, line_count) VALUES (?, ?, ?, ?, 1, 1, ?, ?, ?, 9)');
        for ($i = 0; $i < 250; ++$i) {
            $insert->execute(['pop:' . $i, $this->projectId, sprintf('src/Pop/File%03d.php', $i), 'h', 'php', '1', $ids['scan']]);
        }
        $this->pdo->prepare('UPDATE projects SET root_realpath = ?, active_scan_id = ? WHERE id = ?')->execute([realpath($this->root), $ids['scan'], $this->projectId]);
        (new FolderFactsStore($this->pdo))->replace($this->projectId, (new FolderFactsBuilder($this->pdo))->rows($this->projectId));
        $service = new CreationBriefQueryService($this->pdo);

        $started = hrtime(true);
        $data = $service->creationBrief($this->projectId, 'src/Pop/NewThing.php')->data;
        $milliseconds = (hrtime(true) - $started) / 1e6;

        self::assertSame(250, $data['basis']['files'] ?? null);
        self::assertCount(3, $data['neighbours']);
        self::assertNotSame([], $data['conventions']);
        // The bound catches a change of complexity class, not a regression of tens of milliseconds: CI runs under pcov on slower runners.
        self::assertLessThan(1500.0, $milliseconds);
    }
}
