<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query\CreationBrief;

use InvalidArgumentException;
use Knossos\Query\CreationBrief\CreationBriefQueryService;
use Knossos\Scan\ProjectScanService;
use Knossos\Store\FolderFactsStore;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/** Which folder, companions and signature a brief reads: directories refused, own companions first, wording in the signature. */
#[Group('query')]
final class CreationBriefReadingTest extends KnossosTestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            $this->removeTempTree($root);
        }
        parent::tearDown();
    }

    /**
     * Scan a tree of six `src/Query/<Name><suffix>.php` classes, and a knossos.json with one gate.
     *
     * @return array{0: string, 1: PDO, 2: string, 3: CreationBriefQueryService}
     */
    private function scanned(string $suffix = 'Service', string $command = 'make cs'): array
    {
        $root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        $this->roots[] = $root;
        mkdir($root . '/src/Query', 0o777, true);
        foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $name) {
            file_put_contents($root . "/src/Query/{$name}{$suffix}.php", "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Query;\n\nfinal class {$name}{$suffix} {}\n");
        }
        file_put_contents($root . '/composer.json', '{}');
        file_put_contents($root . '/Makefile', 'all:');
        $this->config($root, $command);
        $pdo = $this->freshTestDatabase();
        $projectId = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root)->projectId;

        return [$root, $pdo, $projectId, new CreationBriefQueryService($pdo)];
    }

    private function config(string $root, string $command): void
    {
        file_put_contents($root . '/knossos.json', json_encode(['version' => 1, 'gates' => [['name' => 'cs', 'command' => $command]]], JSON_THROW_ON_ERROR));
    }

    /** An existing directory is refused with or without a trailing slash; a new file in it is not. */
    public function testAnExistingDirectoryWithoutATrailingSlashIsRefused(): void
    {
        [$root, , $projectId, $service] = $this->scanned();

        foreach (['src/Query', $root . '/src/Query', 'src'] as $path) {
            try {
                $service->creationBrief($projectId, $path);
                self::fail($path . ' was briefed as a new file.');
            } catch (InvalidArgumentException $error) {
                self::assertStringContainsString('names a directory', $error->getMessage(), $path);
            }
        }
        self::assertSame('src/Query/NewService.php', $service->creationBrief($projectId, 'src/Query/NewService.php')->data['path']);
        self::assertSame('src/Query/Missing', $service->creationBrief($projectId, 'src/Query/Missing')->data['path'], 'a name nothing holds is a new file');
    }

    /** A symlink to a directory outside the root is not a directory of the project, so it is briefed as the file it may become. */
    public function testASymlinkedDirectoryOutsideTheRootIsNotFollowed(): void
    {
        [$root, , $projectId, $service] = $this->scanned();
        $outside = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        $this->roots[] = $outside;
        mkdir($outside);
        symlink($outside, $root . '/linked');

        self::assertSame('linked', $service->creationBrief($projectId, 'linked')->data['path']);
    }

    /** A folder with companions of its own uses them, even when its conventions are borrowed; one without falls back to the basis folder. */
    public function testOwnCompanionsComeBeforeTheBorrowedFolders(): void
    {
        [, $pdo, $projectId, $service] = $this->scanned();
        $store = new FolderFactsStore($pdo);
        $store->saveCompanions($projectId, 'head1', 'ok', [
            'src/Query' => [['path' => 'composer.json', 'commits' => 4, 'of' => 4]],
            'src/Query/Sub' => [['path' => 'Makefile', 'commits' => 3, 'of' => 3]],
        ]);

        $own = $service->creationBrief($projectId, 'src/Query/Sub/NewService.php')->data;
        $borrowed = $service->creationBrief($projectId, 'src/Query/Other/NewService.php')->data;

        self::assertTrue($own['basis']['borrowed']);
        self::assertSame(['Makefile'], array_column($own['goes_with'], 'path'));
        self::assertSame(['composer.json'], array_column($borrowed['goes_with'], 'path'));
    }

    /** A changed gate command and a moved suffix change the signature; a convention made of numbers does not. */
    public function testTheSignatureFollowsWhatTheBriefSays(): void
    {
        [$root, , $projectId, $service] = $this->scanned();
        $signature = static fn(string $path = 'src/Query/NewService.php'): string => $service->creationBrief($projectId, $path)->data['signature'];
        $before = $signature();

        $this->config($root, 'make lint');
        self::assertNotSame($before, $signature(), 'a changed gate command');

        [$otherRoot, $otherPdo, $otherId, $other] = $this->scanned('Repository');
        $moved = $other->creationBrief($otherId, 'src/Query/NewRepository.php')->data;
        self::assertNotSame($before, $moved['signature'], 'a suffix that moved');
        self::assertContains('lines', array_column($moved['conventions'], 'id'));

        file_put_contents($otherRoot . '/src/Query/GRepository.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Query;\n\nfinal class GRepository\n{\n" . str_repeat("    // padding\n", 80) . "}\n");
        (new ProjectScanService($otherPdo, self::repositoryRoot(), [$otherRoot]))->scan($otherRoot);
        $grown = $other->creationBrief($otherId, 'src/Query/NewRepository.php')->data;
        self::assertNotSame($moved['conventions'], $grown['conventions'], 'the lines convention moved');
        self::assertSame($moved['signature'], $grown['signature'], 'and the signature did not');
    }
}
