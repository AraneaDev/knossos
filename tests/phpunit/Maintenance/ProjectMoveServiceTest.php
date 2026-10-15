<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Maintenance;

use InvalidArgumentException;
use Knossos\Maintenance\ProjectMoveService;
use Knossos\Scan\ProjectScanService;
use Knossos\Store\SqliteGraphRepository;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * A moved checkout scans as a new project, because the id is the root path.
 * Its notes stay on the old id, invisible, until something carries them over.
 */
final class ProjectMoveServiceTest extends KnossosTestCase
{
    private const OLD_ROOT = '/nonexistent/knossos-move-old/project';

    /** Preview counts what would move and writes nothing. */
    #[Group('maintenance')]
    public function testThePreviewWritesNothing(): void
    {
        [$pdo, $oldId, $newRoot] = $this->movedCheckout();
        try {
            $this->annotate($pdo, $oldId, 'App\\A', 'note', 'model', '2026-10-01T00:00:00Z');
            $this->annotate($pdo, $oldId, 'App\\A', 'intentional', 'user', '2026-10-01T00:00:00Z');

            $result = $this->service($pdo)->move(self::OLD_ROOT, $newRoot, false, $this->scanner($pdo, $newRoot));

            self::assertFalse($result->data['executed']);
            self::assertSame(2, $result->data['annotations']['total']);
            self::assertSame(['user' => 1, 'model' => 1], $result->data['annotations']['by_author']);
            self::assertSame(2, $this->annotationCount($pdo, $oldId), 'A preview moves nothing.');
            self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn(), 'A preview scans nothing.');
        } finally {
            $this->removeTempTree($newRoot);
        }
    }

    /** Every kind and both authors move, the evidence basis with them, and the old project is gone. */
    #[Group('maintenance')]
    public function testExecuteMovesEveryNoteAndRemovesTheOldProject(): void
    {
        [$pdo, $oldId, $newRoot] = $this->movedCheckout();
        try {
            $this->annotate($pdo, $oldId, 'App\\A', 'note', 'model', '2026-10-01T00:00:00Z', '[{"path":"src/A.php"}]');
            $this->annotate($pdo, $oldId, 'App\\A', 'note', 'user', '2026-10-01T00:00:00Z');
            $this->annotate($pdo, $oldId, 'App\\A', 'false_positive', 'model', '2026-10-01T00:00:00Z');

            $result = $this->service($pdo)->move(self::OLD_ROOT, $newRoot, true, $this->scanner($pdo, $newRoot));
            $newId = ProjectScanService::projectIdOf((string) realpath($newRoot));

            self::assertTrue($result->data['executed']);
            self::assertTrue($result->data['scanned'], 'The new root had no project yet, so it was scanned.');
            self::assertSame(3, $this->annotationCount($pdo, $newId));
            self::assertSame('[{"path":"src/A.php"}]', $pdo->query("SELECT basis_json FROM annotations WHERE author = 'model' AND kind = 'note'")->fetchColumn());
            self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM projects WHERE id = ' . $pdo->quote($oldId))->fetchColumn());
            self::assertSame($oldId, $result->data['removed_project']);
        } finally {
            $this->removeTempTree($newRoot);
        }
    }

    /** A note recorded on both sides keeps the later edit, and says which side it kept. */
    #[Group('maintenance')]
    public function testAConflictKeepsTheNewerNote(): void
    {
        [$pdo, $oldId, $newRoot] = $this->movedCheckout();
        try {
            $this->scanner($pdo, $newRoot)->scan($newRoot);
            $newId = ProjectScanService::projectIdOf((string) realpath($newRoot));
            $this->annotate($pdo, $oldId, 'App\\A', 'note', 'model', '2026-10-02T00:00:00Z', value: 'old side, newer');
            $this->annotate($pdo, $newId, 'App\\A', 'note', 'model', '2026-10-01T00:00:00Z', value: 'new side, older');

            $result = $this->service($pdo)->move(self::OLD_ROOT, $newRoot, true, $this->scanner($pdo, $newRoot));

            self::assertFalse($result->data['scanned']);
            self::assertSame(1, $result->data['conflicts']['total']);
            self::assertSame('from', $result->data['conflicts']['items'][0]['kept']);
            self::assertSame('old side, newer', $pdo->query('SELECT value FROM annotations WHERE project_id = ' . $pdo->quote($newId))->fetchColumn());
        } finally {
            $this->removeTempTree($newRoot);
        }
    }

    /** Equal timestamps keep what the target already has. */
    #[Group('maintenance')]
    public function testATieKeepsTheTargetsNote(): void
    {
        [$pdo, $oldId, $newRoot] = $this->movedCheckout();
        try {
            $this->scanner($pdo, $newRoot)->scan($newRoot);
            $newId = ProjectScanService::projectIdOf((string) realpath($newRoot));
            $this->annotate($pdo, $oldId, 'App\\A', 'note', 'user', '2026-10-01T00:00:00Z', value: 'old');
            $this->annotate($pdo, $newId, 'App\\A', 'note', 'user', '2026-10-01T00:00:00Z', value: 'new');

            $result = $this->service($pdo)->move(self::OLD_ROOT, $newRoot, true, $this->scanner($pdo, $newRoot));

            self::assertSame('to', $result->data['conflicts']['items'][0]['kept']);
            self::assertSame('new', $pdo->query('SELECT value FROM annotations WHERE project_id = ' . $pdo->quote($newId))->fetchColumn());
        } finally {
            $this->removeTempTree($newRoot);
        }
    }

    /** A note on a component the new tree lacks still moves; staleness, not the move, judges it. */
    #[Group('maintenance')]
    public function testANoteOnAMissingComponentStillMoves(): void
    {
        [$pdo, $oldId, $newRoot] = $this->movedCheckout();
        try {
            $this->annotate($pdo, $oldId, 'App\\Gone', 'note', 'model', '2026-10-01T00:00:00Z');

            $this->service($pdo)->move(self::OLD_ROOT, $newRoot, true, $this->scanner($pdo, $newRoot));

            self::assertSame(1, $this->annotationCount($pdo, ProjectScanService::projectIdOf((string) realpath($newRoot))));
        } finally {
            $this->removeTempTree($newRoot);
        }
    }

    /** `from` spelled with a trailing slash is the same root. */
    #[Group('maintenance')]
    public function testATrailingSlashOnFromStillFindsTheProject(): void
    {
        [$pdo, , $newRoot] = $this->movedCheckout();
        try {
            $result = $this->service($pdo)->move(self::OLD_ROOT . '/', $newRoot, false, $this->scanner($pdo, $newRoot));

            self::assertSame(self::OLD_ROOT, $result->data['from']['root']);
        } finally {
            $this->removeTempTree($newRoot);
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusals(): iterable
    {
        yield 'unknown source' => ['/nonexistent/never-scanned', 'PROJECT_NOT_FOUND'];
        yield 'target is not a directory' => [self::OLD_ROOT, 'MOVE_TARGET_NOT_DIRECTORY'];
    }

    /** Each refusal leaves the database as it was. */
    #[Group('maintenance')]
    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function testARefusalChangesNothing(string $from, string $code): void
    {
        [$pdo, $oldId, $newRoot] = $this->movedCheckout();
        try {
            $this->annotate($pdo, $oldId, 'App\\A', 'note', 'model', '2026-10-01T00:00:00Z');
            $to = $code === 'MOVE_TARGET_NOT_DIRECTORY' ? $newRoot . '/src/A.php' : $newRoot;

            $error = captureThrows(fn() => $this->service($pdo)->move($from, $to, true, $this->scanner($pdo, $newRoot)), InvalidArgumentException::class);

            self::assertStringStartsWith($code . ':', $error->getMessage());
            self::assertSame(1, $this->annotationCount($pdo, $oldId));
        } finally {
            $this->removeTempTree($newRoot);
        }
    }

    /** An old root still on disk means a second clone; moving would strip the first of its notes. */
    #[Group('maintenance')]
    public function testASourceThatStillExistsIsRefused(): void
    {
        [$pdo, $projectId, $root] = $this->seedProjectWithFiles(['src/a.php']);
        $other = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($other, 0o700);
        try {
            $error = captureThrows(fn() => $this->service($pdo)->move($projectId, $other, true, $this->scanner($pdo, $other)), InvalidArgumentException::class);

            self::assertStringStartsWith('MOVE_SOURCE_STILL_EXISTS:', $error->getMessage());
        } finally {
            $this->removeTempTree($root);
            $this->removeTempTree($other);
        }
    }

    /** An imported bundle has no checkout to have moved. */
    #[Group('maintenance')]
    public function testAnImportedBundleIsRefused(): void
    {
        [$pdo, , $newRoot] = $this->movedCheckout();
        try {
            (new SqliteGraphRepository($pdo))->saveProject('project_bundle', 'Imported', 'bundle://abc');

            $error = captureThrows(fn() => $this->service($pdo)->move('project_bundle', $newRoot, true, $this->scanner($pdo, $newRoot)), InvalidArgumentException::class);

            self::assertStringStartsWith('MOVE_SOURCE_IMPORTED:', $error->getMessage());
        } finally {
            $this->removeTempTree($newRoot);
        }
    }

    /** Moving a project onto its own root is a no-op dressed up as a move. */
    #[Group('maintenance')]
    public function testMovingOntoTheSameProjectIsRefused(): void
    {
        $pdo = $this->freshTestDatabase();
        $root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($root, 0o700);
        try {
            $real = (string) realpath($root);
            // The id the target scans as, recorded under a root that is gone.
            (new SqliteGraphRepository($pdo))->saveProject(ProjectScanService::projectIdOf($real), 'Here', '/nonexistent/gone');

            $error = captureThrows(fn() => $this->service($pdo)->move(ProjectScanService::projectIdOf($real), $root, true, $this->scanner($pdo, $root)), InvalidArgumentException::class);

            self::assertStringStartsWith('MOVE_SAME_PROJECT:', $error->getMessage());
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** The CLI's roots file loses the old grant and gains the new one. */
    #[Group('maintenance')]
    public function testTheRootsFileIsSwappedOnExecute(): void
    {
        [$pdo, , $newRoot] = $this->movedCheckout();
        $rootsDirectory = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($rootsDirectory, 0o700);
        try {
            $roots = new \Knossos\Discovery\RootsFile($rootsDirectory . '/roots.json');
            $roots->write([self::OLD_ROOT]);

            $result = $this->service($pdo)->move(self::OLD_ROOT, $newRoot, true, $this->scanner($pdo, $newRoot), $roots);

            self::assertSame(['added' => true, 'removed' => true], $result->data['roots']);
            self::assertSame([(string) realpath($newRoot)], $roots->read());
        } finally {
            $this->removeTempTree($newRoot);
            $this->removeTempTree($rootsDirectory);
        }
    }

    /**
     * The re-key and the old project's removal are one transaction. Apart, a
     * note written to the old id between the two was deleted with the
     * project, and a removal that failed left the notes moved but the old
     * project behind. Now a failure leaves everything as it was, and running
     * the move again finishes it.
     */
    #[Group('maintenance')]
    public function testAFailedRemovalRollsTheMoveBackAndARerunFinishesIt(): void
    {
        [$pdo, $oldId, $newRoot] = $this->movedCheckout();
        try {
            $this->annotate($pdo, $oldId, 'App\\A', 'note', 'model', '2026-10-01T00:00:00Z');
            $pdo->exec("CREATE TRIGGER refuse_project_delete BEFORE DELETE ON projects BEGIN SELECT RAISE(ABORT, 'refused'); END");

            captureThrows(fn() => $this->service($pdo)->move(self::OLD_ROOT, $newRoot, true, $this->scanner($pdo, $newRoot)), \PDOException::class);

            self::assertSame(1, $this->annotationCount($pdo, $oldId), 'The notes stay where they were when the old project cannot be removed.');
            $pdo->exec('DROP TRIGGER refuse_project_delete');
            $result = $this->service($pdo)->move(self::OLD_ROOT, $newRoot, true, $this->scanner($pdo, $newRoot));
            self::assertSame(1, $this->annotationCount($pdo, ProjectScanService::projectIdOf((string) realpath($newRoot))));
            self::assertSame($oldId, $result->data['removed_project']);
        } finally {
            $this->removeTempTree($newRoot);
        }
    }

    /** A note the target already had and kept was superseded, not moved, and the summary says so. */
    #[Group('maintenance')]
    public function testTheSummaryCountsOnlyWhatMoved(): void
    {
        [$pdo, $oldId, $newRoot] = $this->movedCheckout();
        try {
            $this->scanner($pdo, $newRoot)->scan($newRoot);
            $newId = ProjectScanService::projectIdOf((string) realpath($newRoot));
            $this->annotate($pdo, $oldId, 'App\\A', 'note', 'user', '2026-10-01T00:00:00Z');
            $this->annotate($pdo, $oldId, 'App\\A', 'note', 'model', '2026-10-01T00:00:00Z');
            $this->annotate($pdo, $newId, 'App\\A', 'note', 'user', '2026-10-01T00:00:00Z');

            $result = $this->service($pdo)->move(self::OLD_ROOT, $newRoot, true, $this->scanner($pdo, $newRoot));

            self::assertSame(1, $result->data['moved']);
            self::assertSame(1, $result->data['superseded']);
            self::assertStringStartsWith('Moved 1 annotation', $result->summary);
        } finally {
            $this->removeTempTree($newRoot);
        }
    }

    /**
     * A root whose parent is gone too may be a drive that is not mounted, or a
     * path another machine sees; moving would strip a live checkout. Said in
     * the preview, so it is read before anything is executed.
     */
    #[Group('maintenance')]
    public function testAMissingParentIsWarnedAbout(): void
    {
        [$pdo, , $newRoot] = $this->movedCheckout();
        try {
            $result = $this->service($pdo)->move(self::OLD_ROOT, $newRoot, false, $this->scanner($pdo, $newRoot));

            self::assertNotSame([], array_filter($result->warnings, static fn(string $warning): bool => str_contains($warning, '/nonexistent/knossos-move-old does not exist either')));
        } finally {
            $this->removeTempTree($newRoot);
        }
    }

    /** A gone root under a parent that is still there is the ordinary move, and gets no such warning. */
    #[Group('maintenance')]
    public function testAnOrdinaryMoveIsNotWarnedAbout(): void
    {
        [$pdo, , $newRoot] = $this->movedCheckout();
        $parent = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($parent, 0o700);
        try {
            $gone = $parent . '/project';
            (new SqliteGraphRepository($pdo))->saveProject(StableId::project('root:' . $gone), 'project', $gone);

            $result = $this->service($pdo)->move($gone, $newRoot, false, $this->scanner($pdo, $newRoot));

            self::assertSame([], array_filter($result->warnings, static fn(string $warning): bool => str_contains($warning, 'does not exist either')));
        } finally {
            $this->removeTempTree($newRoot);
            $this->removeTempTree($parent);
        }
    }

    /** A model note on files that did not change is still current after the move. */
    #[Group('maintenance')]
    public function testANoteOnUnchangedFilesIsNotStaleAfterTheMove(): void
    {
        [$pdo, $oldId, $newRoot] = $this->movedCheckout();
        try {
            $hash = hash('sha256', (string) file_get_contents($newRoot . '/src/A.php'));
            $this->annotate($pdo, $oldId, 'App\\A', 'note', 'model', '2026-10-01T00:00:00Z', json_encode([['path' => 'src/A.php', 'hash' => $hash]], JSON_THROW_ON_ERROR));

            $this->service($pdo)->move(self::OLD_ROOT, $newRoot, true, $this->scanner($pdo, $newRoot));
            $notes = (new \Knossos\Query\AnnotationNotes($pdo))->forComponents(ProjectScanService::projectIdOf((string) realpath($newRoot)), ['App\\A']);

            self::assertCount(1, $notes);
            self::assertFalse($notes[0]['stale'], (string) $notes[0]['stale_reason']);
        } finally {
            $this->removeTempTree($newRoot);
        }
    }

    /**
     * A database holding a project whose root is gone, and a new checkout of
     * it on disk with one PHP class.
     *
     * @return array{0: PDO, 1: string, 2: string}
     */
    private function movedCheckout(): array
    {
        $pdo = $this->freshTestDatabase();
        $oldId = StableId::project('root:' . self::OLD_ROOT);
        (new SqliteGraphRepository($pdo))->saveProject($oldId, 'project', self::OLD_ROOT);
        $newRoot = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($newRoot . '/src', 0o700, true);
        file_put_contents($newRoot . '/src/A.php', "<?php\nnamespace App;\nfinal class A {}\n");

        return [$pdo, $oldId, $newRoot];
    }

    private function annotate(PDO $pdo, string $projectId, string $component, string $kind, string $author, string $updatedAt, string $basis = '[]', string $value = 'v'): void
    {
        $pdo->prepare(
            'INSERT INTO annotations(project_id, canonical_name, kind, value, author, created_at, updated_at, basis_json) ' .
            'VALUES (:project, :component, :kind, :value, :author, :at, :at, :basis)',
        )->execute(['project' => $projectId, 'component' => $component, 'kind' => $kind, 'value' => $value, 'author' => $author, 'at' => $updatedAt, 'basis' => $basis]);
    }

    private function annotationCount(PDO $pdo, string $projectId): int
    {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM annotations WHERE project_id = :project');
        $statement->execute(['project' => $projectId]);

        return (int) $statement->fetchColumn();
    }

    private function service(PDO $pdo): ProjectMoveService
    {
        return new ProjectMoveService($pdo);
    }

    private function scanner(PDO $pdo, string $root): ProjectScanService
    {
        return new ProjectScanService($pdo, self::repositoryRoot(), [$root]);
    }
}
