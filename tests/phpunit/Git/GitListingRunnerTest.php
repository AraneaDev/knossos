<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Git;

use Knossos\Git\DirtyPathResolver;
use Knossos\Git\GitListingRunner;
use Knossos\Git\GitProcessRunner;
use Knossos\Git\ProcessGitWorkingTreeProvider;
use Knossos\Query\Drift\GitDriftOracle;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use ReflectionProperty;

/**
 * Git answers that list every path are read under their own ceiling, and the
 * callers that ask for such a listing use it by default.
 */
#[Group('git')]
final class GitListingRunnerTest extends KnossosTestCase
{
    /**
     * A listing of every tracked path grows with the repository, not with the
     * question: about 120 bytes an entry, so 20,000 tracked files already pass
     * the 2 MB that bounds every other answer. The listing runner reads such
     * an answer whole, while the default runner still refuses it.
     */
    public function testAListingRunnerReadsAnAnswerPastTheDefaultCeiling(): void
    {
        $dir = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(8));
        mkdir($dir, 0o700, true);
        $fakeGit = $dir . '/git';
        file_put_contents($fakeGit, "#!/bin/sh\nhead -c 3000000 /dev/zero\n");
        chmod($fakeGit, 0o700);
        try {
            self::assertSame(3_000_000, strlen(GitListingRunner::create()->run([$fakeGit, 'ls-tree'], 5000, 'listing probe')));
            self::assertGreaterThan(2_000_000, GitListingRunner::OUTPUT_BYTES);

            $this->expectExceptionMessage('Git listing probe output exceeded its configured byte limit.');
            (new GitProcessRunner())->run([$fakeGit, 'ls-tree'], 5000, 'listing probe');
        } finally {
            $this->removeTempTree($dir);
        }
    }

    /**
     * The tree is listed whole, so its size follows the repository: a runner
     * held to the 2 MB that bounds every other git answer refuses the listing
     * of a repository with some 20,000 tracked files, and the scan then
     * records no set at all.
     */
    public function testTheScanResolverIsSizedForAWholeTreeListing(): void
    {
        $runner = (new ReflectionProperty(DirtyPathResolver::class, 'runner'))->getValue(new DirtyPathResolver());

        self::assertInstanceOf(GitProcessRunner::class, $runner);
        self::assertSame(
            GitListingRunner::OUTPUT_BYTES,
            (new ReflectionProperty(GitProcessRunner::class, 'maxOutputBytes'))->getValue($runner),
        );
    }

    /**
     * `ls-files --cached` names every tracked path, so its size follows the
     * repository. Read under the 2 MB that bounds an ordinary git answer, it
     * fails at some 20,000 tracked files and the oracle declines on every
     * probe of exactly the repositories the walk is slowest for.
     */
    public function testTheDriftOracleIsSizedForAListingOfEveryTrackedPath(): void
    {
        $runner = (new ReflectionProperty(GitDriftOracle::class, 'runner'))->getValue(new GitDriftOracle($this->freshTestDatabase()));

        self::assertInstanceOf(GitProcessRunner::class, $runner);
        self::assertSame(
            GitListingRunner::OUTPUT_BYTES,
            (new ReflectionProperty(GitProcessRunner::class, 'maxOutputBytes'))->getValue($runner),
        );
    }

    /**
     * The untracked listing names every file the tree's own .gitignore files
     * do not exclude, so its size follows the checkout. The 2 MB that bounds
     * an ordinary answer failed the whole query before `max_files` could cut
     * the list.
     */
    public function testTheWorkingTreeRunnerIsSizedForAListing(): void
    {
        $runner = (new ReflectionProperty(ProcessGitWorkingTreeProvider::class, 'runner'))->getValue(new ProcessGitWorkingTreeProvider());

        self::assertSame(GitListingRunner::OUTPUT_BYTES, (new ReflectionProperty(GitProcessRunner::class, 'maxOutputBytes'))->getValue($runner));
    }
}
