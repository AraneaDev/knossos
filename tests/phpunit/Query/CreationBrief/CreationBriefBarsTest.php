<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query\CreationBrief;

use Knossos\Query\CreationBrief\CreationBriefQueryService;
use Knossos\Query\CreationBrief\CreationBriefRenderer;
use Knossos\Scan\ProjectScanService;
use Knossos\Store\FolderFactsStore;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * How often something must hold before the brief names it: a companion in four of
 * five additions, an expected test when three in five of the folder's files have theirs there.
 */
#[Group('query')]
final class CreationBriefBarsTest extends KnossosTestCase
{
    private string $root = '';

    private PDO $pdo;

    private string $projectId = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = self::scratchPath('knossos-stale');
        mkdir($this->root . '/src/Query', 0o777, true);
        foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $name) {
            file_put_contents($this->root . "/src/Query/{$name}Service.php", "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Query;\n\nfinal class {$name}Service {}\n");
        }
        file_put_contents($this->root . '/knossos.json', '{"version": 1}');
        file_put_contents($this->root . '/Makefile', 'all:');
        $this->pdo = $this->freshTestDatabase();
        $this->projectId = (new ProjectScanService($this->pdo, self::repositoryRoot(), [$this->root]))->scan($this->root)->projectId;
    }

    protected function tearDown(): void
    {
        $this->removeTempTree($this->root);
        parent::tearDown();
    }

    /** A companion in three of four additions is stored and not named; one in four of five is named. */
    public function testACompanionIsNamedFromFourInFive(): void
    {
        $store = new FolderFactsStore($this->pdo);
        $store->saveCompanions($this->projectId, 'head', 'ok', ['src/Query' => [
            ['path' => 'Makefile', 'commits' => 3, 'of' => 4],
            ['path' => 'knossos.json', 'commits' => 4, 'of' => 5],
        ]]);

        self::assertSame([['path' => 'knossos.json', 'commits' => 4, 'of' => 5]], $this->brief()['goes_with']);
        self::assertCount(2, $store->companionsOf($this->projectId, 'src/Query'), 'both stay stored, so the bar can move without a rescan');
    }

    /** Six of ten files with their test in one place name the new file's test there, with the count. */
    public function testATestPatternFollowedBySixOfTenIsNamedWithItsCount(): void
    {
        $this->pattern('tests/Query/<name>Test.php', 6, 10);

        $data = $this->brief();
        $lines = explode("\n", CreationBriefRenderer::lines($data));
        $here = array_key_first(array_filter($lines, static fn(string $line): bool => str_starts_with($line, 'here')));

        self::assertSame(['path' => 'tests/Query/FooServiceTest.php', 'files' => 6, 'of' => 10], $data['test']);
        self::assertSame('test: tests/Query/FooServiceTest.php (6 of 10 here have theirs there)', $lines[$here + 1] ?? null);
    }

    /** Five of ten is half the folder, not where its tests go. */
    public function testATestPatternFollowedByFiveOfTenIsNotNamed(): void
    {
        $this->pattern('tests/Query/<name>Test.php', 5, 10);

        $data = $this->brief();

        self::assertNull($data['test']);
        self::assertStringNotContainsString('test:', CreationBriefRenderer::lines($data));
    }

    /** The signature follows the test pattern, not how many files follow it. */
    public function testTheSignatureCoversThePatternAndNotTheCount(): void
    {
        $this->pattern('tests/Query/<name>Test.php', 6, 10);
        $before = $this->brief()['signature'];
        $this->pattern('tests/Query/<name>Test.php', 7, 10);
        $counted = $this->brief()['signature'];
        $this->pattern('tests/Unit/<name>Test.php', 7, 10);

        self::assertSame($before, $counted);
        self::assertNotSame($before, $this->brief()['signature']);
    }

    /** A pattern three in five of a folder's files follow is enough to tell a new path is a test; two in five is not. */
    public function testAPathIsATestByAPatternThreeInFiveFollow(): void
    {
        $insert = $this->pdo->prepare('INSERT INTO folder_facts (project_id, directory, extension, role, files, facts_json) VALUES (?, ?, ?, ?, ?, ?)');
        $insert->execute([$this->projectId, 'lib/three', 'php', 'source', 5, json_encode(['test_path' => ['pattern' => '<dir>/<name>.spec.php', 'files' => 3]], JSON_THROW_ON_ERROR)]);
        $insert->execute([$this->projectId, 'lib/two', 'php', 'source', 5, json_encode(['test_path' => ['pattern' => '<dir>/<name>.check.php', 'files' => 2]], JSON_THROW_ON_ERROR)]);

        self::assertSame('test', $this->brief('lib/new/Thing.spec.php')['role']);
        self::assertSame('source', $this->brief('lib/new/Thing.check.php')['role']);
    }

    /** @return array<string, mixed> */
    private function brief(string $path = 'src/Query/FooService.php'): array
    {
        return (new CreationBriefQueryService($this->pdo))->creationBrief($this->projectId, $path)->data;
    }

    /** Make `src/Query` a folder of `$of` files of which `$files` have their test at `$pattern`. */
    private function pattern(string $pattern, int $files, int $of): void
    {
        $this->pdo->prepare("UPDATE folder_facts SET files = ?, facts_json = json_set(facts_json, '$.test_path', json_object('pattern', ?, 'files', CAST(? AS INTEGER))) WHERE project_id = ? AND directory = 'src/Query' AND extension = 'php' AND role = 'source'")
            ->execute([$of, $pattern, $files, $this->projectId]);
    }
}
