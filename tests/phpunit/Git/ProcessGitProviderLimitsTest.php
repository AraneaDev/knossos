<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Git;

use Knossos\Git\GitOutputLimitExceeded;
use Knossos\Git\ProcessGitHistoryProvider;
use Knossos\Git\ProcessGitWorkingTreeProvider;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

/**
 * What the git providers do with an answer at the edge of what they can read:
 * a log past the output limit, and a path PHP would rather call a number.
 */
#[Group('git')]
final class ProcessGitProviderLimitsTest extends KnossosTestCase
{
    /** One commit touching one file, as `git log -z` writes it with the provider's format. */
    private const LOG = "\0KNOSSOS_COMMIT\x1faaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\x1f2026-07-20T10:00:00+00:00\x1f1784541600\x1fa@test.dev\0\nsrc/a.php\0";

    /**
     * A changed file named in digits alone stays a string. Collected as an
     * array key PHP makes "123" an int, and every consumer is typed for a
     * path.
     */
    public function testAChangedPathOfDigitsAloneStaysAString(): void
    {
        $runner = new RecordingGitRunner(static fn(array $command): string => match (true) {
            in_array('rev-parse', $command, true) => "abc\n",
            in_array('diff', $command, true) => "M\000123\0",
            default => "789\0",
        });

        $result = (new ProcessGitWorkingTreeProvider(runner: $runner))->changes(sys_get_temp_dir(), null, 10, 100);

        self::assertSame(['123', '789'], $result['paths']);
    }

    /**
     * A log past the output limit is asked for again with a tenth of the
     * commits and reported as cut, so the ranking keeps a change signal.
     */
    public function testALogPastTheOutputLimitIsRetriedWithFewerCommitsAndSaysItWasCut(): void
    {
        $log = self::LOG;
        $runner = new RecordingGitRunner(static function (array $command) use ($log): string {
            if (in_array('--max-count=501', $command, true)) {
                throw new GitOutputLimitExceeded('Git history output exceeded its configured byte limit.');
            }

            return $log;
        });

        $result = (new ProcessGitHistoryProvider(runner: $runner))->history(sys_get_temp_dir(), 30, 500, 100);

        self::assertSame([true, 1, ['src/a.php']], [$result['truncated'], $result['commits_examined'], array_keys($result['files'])]);
        self::assertSame(['--max-count=501', '--max-count=50'], array_map(
            static fn(array $command): string => array_values(array_filter($command, static fn(string $arg): bool => str_starts_with($arg, '--max-count=')))[0],
            $runner->commandsWith('log'),
        ));
    }

    /** A log that is still too large at a tenth fails with the first error, and one commit cannot be asked for in fewer. */
    public function testALogThatNeverFitsFailsWithTheLimitError(): void
    {
        $runner = new RecordingGitRunner(static fn(array $command): string => throw new GitOutputLimitExceeded('too large at ' . implode(' ', array_filter($command, static fn(string $arg): bool => str_starts_with($arg, '--max-count=')))));

        try {
            (new ProcessGitHistoryProvider(runner: $runner))->history(sys_get_temp_dir(), 30, 500, 100);
            self::fail('A log that never fits must fail.');
        } catch (GitOutputLimitExceeded $error) {
            self::assertSame('too large at --max-count=501', $error->getMessage());
        }
        self::assertCount(2, $runner->commandsWith('log'));

        $single = new RecordingGitRunner(static fn(array $command): string => throw new GitOutputLimitExceeded('too large'));
        try {
            (new ProcessGitHistoryProvider(runner: $single))->history(sys_get_temp_dir(), 30, 9, 100);
            self::fail('A log that never fits must fail.');
        } catch (GitOutputLimitExceeded) {
        }
        self::assertCount(1, $single->commandsWith('log'), 'A tenth of nine commits is none, so there is nothing smaller to ask for.');
    }

    /** Any other failure is not retried: a directory that is no repository fails the same way however little is asked. */
    public function testAFailureThatIsNotTheOutputLimitIsNotRetried(): void
    {
        $runner = new RecordingGitRunner(static fn(array $command): string => throw new RuntimeException('Git history unavailable: not a git repository'));

        try {
            (new ProcessGitHistoryProvider(runner: $runner))->history(sys_get_temp_dir(), 30, 500, 100);
            self::fail('The failure must surface.');
        } catch (RuntimeException $error) {
            self::assertSame('Git history unavailable: not a git repository', $error->getMessage());
        }
        self::assertCount(1, $runner->commandsWith('log'));
    }
}
