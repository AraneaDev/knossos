<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query\CreationBrief;

use Knossos\Query\CreationBrief\CreationBriefQueryService;
use Knossos\Query\CreationBrief\CreationBriefRenderer;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * A folder with too few files of its own learns from its nearest ancestor's whole
 * subtree: `src/App` holds two classes and its subfolders six more, `src/Big` five.
 */
#[Group('query')]
final class CreationBriefSubtreeTest extends KnossosTestCase
{
    private string $root = '';

    private PDO $pdo;

    private string $projectId = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = self::scratchPath('knossos-stale');
        foreach (['src/App' => ['A', 'B'], 'src/App/One' => ['C', 'D', 'E'], 'src/App/Two' => ['F', 'G', 'H'], 'src/Big' => ['P', 'Q', 'R', 'S', 'T']] as $directory => $names) {
            mkdir($this->root . '/' . $directory, 0o777, true);
            foreach ($names as $name) {
                file_put_contents($this->root . "/{$directory}/{$name}.php", "<?php\n\ndeclare(strict_types=1);\n\nnamespace App;\n\nfinal class {$name}\n{\n    public function __construct(private \\PDO \$pdo) {}\n}\n");
            }
        }
        $this->pdo = $this->freshTestDatabase();
        $this->projectId = (new ProjectScanService($this->pdo, self::repositoryRoot(), [$this->root]))->scan($this->root)->projectId;
    }

    protected function tearDown(): void
    {
        $this->removeTempTree($this->root);
        parent::tearDown();
    }

    /**
     * A new folder under `src/App` is briefed from all eight classes there, with only what transfers between folders:
     * the name case, which counts the two files of `src/App` too though that folder alone has too few to store one.
     * The per-style counts behind it never reach the brief.
     */
    public function testANewFolderLearnsFromItsAncestorsSubtree(): void
    {
        $data = $this->brief('src/App/New/X.php');

        self::assertSame(['directory' => 'src/App', 'files' => 8, 'borrowed' => true, 'subtree' => true], $data['basis']);
        self::assertSame([['id' => 'name_case', 'text' => 'PascalCase file names', 'files' => 8, 'of' => 8]], $data['conventions']);
        self::assertStringNotContainsString('name_styles', CreationBriefRenderer::lines($data) . json_encode($data, JSON_THROW_ON_ERROR));
        self::assertCount(3, $data['neighbours']);
        foreach ($data['neighbours'] as $neighbour) {
            self::assertStringStartsWith('src/App/', $neighbour);
        }
    }

    /** A folder of two files with six more below it is briefed from its own subtree. */
    public function testASmallFolderWithEnoughBelowItIsItsOwnSubtree(): void
    {
        self::assertSame(['directory' => 'src/App', 'files' => 8, 'borrowed' => true, 'subtree' => true], $this->brief('src/App/Y.php')['basis']);
        self::assertSame(['directory' => 'src/App', 'files' => 8, 'borrowed' => true, 'subtree' => true], $this->brief('src/App/One/Y.php')['basis']);
    }

    /** A folder with five files of its own is briefed from them alone, as before. */
    public function testAFolderWithFiveFilesIsItsOwnBasis(): void
    {
        $data = $this->brief('src/Big/U.php');

        self::assertSame(['directory' => 'src/Big', 'files' => 5, 'borrowed' => false, 'subtree' => false], $data['basis']);
        self::assertContains('injects:PDO', array_column($data['conventions'], 'id'));
    }

    /**
     * Every class in `src/App` and below is final and alone in its file, and a subtree still says neither:
     * a kind or a modifier learnt across folders was followed far less often than one learnt in the folder itself.
     * The five classes of `src/Big`, the same shape as a direct population, say both.
     */
    public function testASubtreeStatesNoKindOrModifierThatADirectPopulationDoes(): void
    {
        $subtree = array_column($this->brief('src/App/New/X.php')['conventions'], 'id');
        $direct = array_column($this->brief('src/Big/U.php')['conventions'], 'id');

        self::assertNotContains('kind', $subtree);
        self::assertNotContains('modifier:final', $subtree);
        self::assertContains('kind', $direct);
        self::assertContains('modifier:final', $direct);
    }

    /** Across a subtree only a `<dir>` test pattern names a test; a literal test folder is one folder's. */
    public function testOnlyADirectoryRelativePatternNamesATestForASubtree(): void
    {
        $this->pattern('tests/App/<name>Test.php');
        $literal = $this->brief('src/App/New/X.php');
        $this->pattern('<dir>/<name>.spec.php');
        $relative = $this->brief('src/App/New/X.php');

        self::assertNull($literal['test']);
        self::assertSame(['path' => 'src/App/New/X.spec.php', 'files' => 8, 'of' => 8], $relative['test']);
    }

    /** With nothing nearer, the whole project is the basis, and the brief says so. */
    public function testTheRootAsBasisIsTheWholeProject(): void
    {
        $data = $this->brief('lib/New/X.php');

        self::assertSame(['directory' => '', 'files' => 13, 'borrowed' => true, 'subtree' => true], $data['basis']);
        self::assertStringContainsString("\nhere (across the project): 13 of 13 PascalCase file names", CreationBriefRenderer::lines($data));
    }

    /** @return array<string, mixed> */
    private function brief(string $path): array
    {
        return (new CreationBriefQueryService($this->pdo))->creationBrief($this->projectId, $path)->data;
    }

    /** Give every `src/App` row a test pattern that all its files follow. */
    private function pattern(string $pattern): void
    {
        $this->pdo->prepare("UPDATE folder_facts SET facts_json = json_set(facts_json, '$.test_path', json_object('pattern', ?, 'files', files)) WHERE project_id = ? AND (directory = 'src/App' OR directory LIKE 'src/App/%')")
            ->execute([$pattern, $this->projectId]);
    }
}
