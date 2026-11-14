<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query\CreationBrief;

use Knossos\Query\CreationBrief\NeighbourCandidates;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/** How many files a brief weighs as neighbours: a large subtree is cut to the files nearest the new path. */
#[Group('query')]
final class NeighbourCandidatesTest extends KnossosTestCase
{
    private PDO $pdo;

    private string $projectId = '';

    /** @var array<string, string> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        [$this->pdo, , $this->ids] = $this->storeFixture();
        $this->projectId = $this->ids['project'];
        $this->pdo->prepare('DELETE FROM files WHERE project_id = ?')->execute([$this->projectId]);
        $this->pdo->prepare('DELETE FROM nodes WHERE project_id = ?')->execute([$this->projectId]);
    }

    /** Of 250 files below `src/Pop`, the 200 nearest `src/Pop/a/` are weighed: all 30 there, then the rest by path. */
    public function testASubtreeIsCutToTheFilesNearestTheNewPath(): void
    {
        $this->files('src/Pop/a/F%03d.php', 30);
        $this->files('src/Pop/b/F%03d.php', 210);
        $this->files('src/Pop/F%03d.php', 10);

        $paths = array_column($this->candidates('src/Pop', 'src/Pop/a/New.php', true), 'path');
        $again = array_column($this->candidates('src/Pop', 'src/Pop/a/New.php', true), 'path');

        self::assertCount(200, $paths);
        self::assertSame($paths, $again);
        self::assertCount(30, array_filter($paths, static fn(string $path): bool => str_starts_with($path, 'src/Pop/a/')));
        self::assertContains('src/Pop/F000.php', $paths, 'src/Pop shares a shorter prefix than src/Pop/a, longer than none, and sorts before src/Pop/b');
        self::assertContains('src/Pop/b/F159.php', $paths);
        self::assertNotContains('src/Pop/b/F160.php', $paths);
    }

    /** A direct population is weighed whole, however large. */
    public function testADirectPopulationIsWeighedWhole(): void
    {
        $this->files('src/Pop/F%03d.php', 250);

        self::assertCount(250, $this->candidates('src/Pop', 'src/Pop/New.php', false));
    }

    /** @return list<array{path: string, uses: list<string>}> */
    private function candidates(string $directory, string $exclude, bool $subtree): array
    {
        return (new NeighbourCandidates($this->pdo))->of($this->projectId, $directory, 'php', 'source', $exclude, $subtree);
    }

    /** Insert `$count` files named by the `sprintf` pattern. */
    private function files(string $pattern, int $count): void
    {
        $insert = $this->pdo->prepare('INSERT INTO files (id, project_id, relative_path, content_hash, size, mtime, language, scanner_version, last_scan_id, line_count) VALUES (?, ?, ?, ?, 1, 1, ?, ?, ?, 9)');
        for ($i = 0; $i < $count; ++$i) {
            $path = sprintf($pattern, $i);
            $insert->execute(['f:' . $path, $this->projectId, $path, 'h', 'php', '1', $this->ids['scan']]);
        }
    }
}
