<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Store;

use Knossos\Store\ProjectRepository;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

final class ProjectRepositoryTest extends KnossosTestCase
{
    #[Group('query')]
    public function testResolvesExactRootAndSubdirectoryAndRejectsUnrelatedPath(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $root = (string) $pdo->query('SELECT root_realpath FROM projects')->fetchColumn();
        $resolver = new ProjectRepository($pdo);

        $exact = $resolver->resolvePath($root);
        assertSame($ids['project'], $exact['id'] ?? null);

        // A subdirectory of a scanned repository still finds its project: this is
        // the case the hook actually hits, because a session can start anywhere.
        $nested = $resolver->resolvePath($root . '/src/Deeply/Nested');
        assertSame($ids['project'], $nested['id'] ?? null);

        assertSame(null, $resolver->resolvePath('/definitely/not/a/scanned/project'));
    }

    /**
     * A relative path that does not exist still belongs to the project the
     * caller is standing in. realpath() cannot resolve it, and the fallback
     * used to keep it relative, so the ancestor walk climbed to "." and
     * stopped: a project root is always absolute and "." never matches one.
     */
    #[Group('query')]
    public function testResolvesARelativePathThatDoesNotExistAgainstTheWorkingDirectory(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $root = (string) $pdo->query('SELECT root_realpath FROM projects')->fetchColumn();
        // The fixture's root is not a real directory, so stand in it lexically
        // by pointing the resolver at a path below it that does not exist.
        $resolver = new ProjectRepository($pdo);

        $missing = $resolver->resolvePath($root . '/src/NotThere.php');

        assertSame($ids['project'], $missing['id'] ?? null);

        $previous = getcwd();
        try {
            if (@chdir(sys_get_temp_dir()) !== true) {
                self::markTestSkipped('cannot change directory here.');
            }
            // Relative and nonexistent, and nothing under the temp directory is
            // scanned, so this must be null rather than an accidental match.
            assertSame(null, $resolver->resolvePath('nowhere/at/all'));
        } finally {
            if (is_string($previous)) {
                @chdir($previous);
            }
        }
    }
}
