<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Scan\ProjectScanService;
use Knossos\Store\{SqliteGraphRepository, StableId};
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * A moved checkout scans as a new project and nothing says its notes were
 * left behind. The first scan of the new root says so, and names the command.
 */
final class OrphanedProjectHintTest extends KnossosTestCase
{
    /** An orphan with the same name whose notes name this tree's components is pointed at. */
    #[Group('scan')]
    public function testAMatchingOrphanIsNamed(): void
    {
        [$pdo, $parent, $root] = $this->checkout(['A', 'B']);
        try {
            $gone = $this->goneRoot($parent, 'old', 'project');
            $this->orphan($pdo, $gone, ['App\\A', 'App\\B']);

            $warnings = $this->scan($pdo, $root)->warnings;

            self::assertCount(1, array_filter($warnings, static fn(string $warning): bool => str_contains($warning, 'knossos move-project ' . escapeshellarg($gone) . ' ' . escapeshellarg((string) realpath($root)) . ' --execute')));
            self::assertCount(1, array_filter($warnings, static fn(string $warning): bool => str_contains($warning, '2 components its notes name exist in this tree')));
        } finally {
            $this->removeTempTree($parent);
        }
    }

    /** A different checkout name is a different project, however much it shares. */
    #[Group('scan')]
    public function testADifferentBasenameGivesNoHint(): void
    {
        [$pdo, $parent, $root] = $this->checkout(['A']);
        try {
            $this->orphan($pdo, $this->goneRoot($parent, 'old', 'other'), ['App\\A']);

            self::assertSame([], self::hints($this->scan($pdo, $root)->warnings));
        } finally {
            $this->removeTempTree($parent);
        }
    }

    /** An old root still on disk is a second clone, not an orphan. */
    #[Group('scan')]
    public function testAnOldRootThatStillExistsGivesNoHint(): void
    {
        [$pdo, $parent, $root] = $this->checkout(['A']);
        $otherParent = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($otherParent . '/project', 0o700, true);
        try {
            $this->orphan($pdo, $otherParent . '/project', ['App\\A']);

            self::assertSame([], self::hints($this->scan($pdo, $root)->warnings));
        } finally {
            $this->removeTempTree($parent);
            $this->removeTempTree($otherParent);
        }
    }

    /** One component in three is a coincidence, not the same project. */
    #[Group('scan')]
    public function testTooFewNamedComponentsGivesNoHint(): void
    {
        [$pdo, $parent, $root] = $this->checkout(['A']);
        try {
            $this->orphan($pdo, $this->goneRoot($parent, 'old', 'project'), ['App\\A', 'App\\X', 'App\\Y']);

            self::assertSame([], self::hints($this->scan($pdo, $root)->warnings));
        } finally {
            $this->removeTempTree($parent);
        }
    }

    /** Only the scan that created the project looks; later scans pay nothing. */
    #[Group('scan')]
    public function testARescanGivesNoHint(): void
    {
        [$pdo, $parent, $root] = $this->checkout(['A']);
        try {
            $this->orphan($pdo, $this->goneRoot($parent, 'old', 'project'), ['App\\A']);
            $this->scan($pdo, $root);
            file_put_contents($root . '/src/A.php', "<?php\nnamespace App;\nfinal class A { public function run(): void {} }\n");

            self::assertSame([], self::hints($this->scan($pdo, $root)->warnings));
        } finally {
            $this->removeTempTree($parent);
        }
    }

    /** Two old clones of one name: the one whose notes match better is named, and only it. */
    #[Group('scan')]
    public function testTheBetterOfTwoOrphansIsNamed(): void
    {
        [$pdo, $parent, $root] = $this->checkout(['A', 'B']);
        try {
            $this->orphan($pdo, $this->goneRoot($parent, 'half', 'project'), ['App\\A', 'App\\Q']);
            $full = $this->goneRoot($parent, 'full', 'project');
            $this->orphan($pdo, $full, ['App\\A', 'App\\B']);

            $hints = self::hints($this->scan($pdo, $root)->warnings);

            self::assertCount(1, $hints);
            self::assertStringContainsString(escapeshellarg($full), $hints[0]);
        } finally {
            $this->removeTempTree($parent);
        }
    }

    /**
     * A root whose parent is gone too may be an unmounted drive or a path only
     * another machine sees; the checkout may not have moved at all.
     */
    #[Group('scan')]
    public function testAnOrphanWhoseParentIsGoneTooGivesNoHint(): void
    {
        [$pdo, $parent, $root] = $this->checkout(['A']);
        try {
            $this->orphan($pdo, '/nonexistent/knossos-hint/project', ['App\\A']);

            self::assertSame([], self::hints($this->scan($pdo, $root)->warnings));
        } finally {
            $this->removeTempTree($parent);
        }
    }

    /** A root that is gone under a parent that is still there: what a moved checkout leaves. */
    private function goneRoot(string $parent, string $under, string $basename): string
    {
        mkdir($parent . '/' . $under, 0o700, true);

        return $parent . '/' . $under . '/' . $basename;
    }

    /**
     * A database and a checkout at <temp>/project holding one class per name.
     *
     * @param list<string> $classes
     * @return array{0: PDO, 1: string, 2: string}
     */
    private function checkout(array $classes): array
    {
        $parent = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        $root = $parent . '/project';
        mkdir($root . '/src', 0o700, true);
        foreach ($classes as $class) {
            file_put_contents($root . '/src/' . $class . '.php', "<?php\nnamespace App;\nfinal class {$class} {}\n");
        }

        return [$this->freshTestDatabase(), $parent, $root];
    }

    /**
     * A project row for a root that is gone, with a note on each component.
     *
     * @param list<string> $components
     */
    private function orphan(PDO $pdo, string $root, array $components): void
    {
        $projectId = StableId::project('root:' . $root);
        (new SqliteGraphRepository($pdo))->saveProject($projectId, basename($root), $root);
        $insert = $pdo->prepare(
            'INSERT INTO annotations(project_id, canonical_name, kind, value, author, created_at, updated_at) ' .
            "VALUES (:project, :component, 'note', 'v', 'model', '2026-10-01T00:00:00Z', '2026-10-01T00:00:00Z')",
        );
        foreach ($components as $component) {
            $insert->execute(['project' => $projectId, 'component' => $component]);
        }
    }

    private function scan(PDO $pdo, string $root): \Knossos\Result\ResultEnvelope
    {
        return (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);
    }

    /**
     * @param list<string> $warnings
     * @return list<string>
     */
    private static function hints(array $warnings): array
    {
        return array_values(array_filter($warnings, static fn(string $warning): bool => str_contains($warning, 'knossos move-project')));
    }
}
