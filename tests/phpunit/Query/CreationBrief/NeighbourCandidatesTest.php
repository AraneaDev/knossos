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

    /** 250 tests under `src/__tests__` sort before the sources beside the new file, and still do not crowd them out. */
    public function testTestsBelowDoNotCrowdOutTheSourcesOfASubtree(): void
    {
        $this->files('src/__tests__/t%03d.spec.ts', 250, test: true);
        $this->files('src/s%d.ts', 6);

        $paths = array_column($this->candidates('src', 'src/foo.ts', true, 'ts'), 'path');

        self::assertSame(['src/s0.ts', 'src/s1.ts', 'src/s2.ts', 'src/s3.ts', 'src/s4.ts', 'src/s5.ts'], $paths);
    }

    /** A new test weighs the tests of the subtree, cut to 200, and no source. */
    public function testATestWeighsOnlyTheTestsOfASubtree(): void
    {
        $this->files('src/__tests__/t%03d.spec.ts', 250, test: true);
        $this->files('src/s%d.ts', 6);

        $paths = array_column($this->candidates('src', 'src/__tests__/new.spec.ts', true, 'ts', 'test'), 'path');

        self::assertCount(200, $paths);
        self::assertSame([], array_values(array_filter($paths, static fn(string $path): bool => !str_starts_with($path, 'src/__tests__/'))));
    }

    /** A direct population is weighed whole, however large. */
    public function testADirectPopulationIsWeighedWhole(): void
    {
        $this->files('src/Pop/F%03d.php', 250);

        self::assertCount(250, $this->candidates('src/Pop', 'src/Pop/New.php', false));
    }

    /** @return list<array{path: string, uses: list<string>}> */
    private function candidates(string $directory, string $exclude, bool $subtree, string $extension = 'php', string $role = 'source'): array
    {
        return (new NeighbourCandidates($this->pdo))->of($this->projectId, $directory, $extension, $role, $exclude, $subtree);
    }

    /** Insert `$count` files named by the `sprintf` pattern; a test file holds a module classified as a test. */
    private function files(string $pattern, int $count, bool $test = false): void
    {
        $insert = $this->pdo->prepare('INSERT INTO files (id, project_id, relative_path, content_hash, size, mtime, language, scanner_version, last_scan_id, line_count) VALUES (?, ?, ?, ?, 1, 1, ?, ?, ?, 9)');
        $node = $this->pdo->prepare('INSERT INTO nodes (id, project_id, language, kind, canonical_name, display_name, parent_id, file_id, origin, confidence, attributes_json, owner_key, last_scan_id) VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?)');
        $classify = $this->pdo->prepare('INSERT INTO classifications (id, project_id, node_id, role, origin, confidence, rule_id, file_id, last_scan_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        for ($i = 0; $i < $count; ++$i) {
            $path = sprintf($pattern, $i);
            $insert->execute(['f:' . $path, $this->projectId, $path, 'h', 'php', '1', $this->ids['scan']]);
            if ($test) {
                $node->execute(['n:' . $path, $this->projectId, 'ts', 'module', $path, $path, 'f:' . $path, 'ast', 'certain', '{}', 'k:' . $path, $this->ids['scan']]);
                $classify->execute(['c:' . $path, $this->projectId, 'n:' . $path, 'quality.test_module', 'rule', 'certain', 'r', 'f:' . $path, $this->ids['scan']]);
            }
        }
    }
}
