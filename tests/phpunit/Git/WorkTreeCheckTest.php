<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Git;

use Knossos\Git\NotAWorkTree;
use Knossos\Git\ProcessGitWorkingTreeProvider;
use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

/**
 * The working-tree reader's first question, `rev-parse --is-inside-work-tree`
 * (G6-08a): a "false" (git run inside a `.git` directory) is no work tree,
 * as a failed check is, and the answer says whether `HEAD` names a commit.
 */
#[Group('git')]
final class WorkTreeCheckTest extends KnossosTestCase
{
    public function testAFalseAnswerIsNotAWorkTree(): void
    {
        $runner = new RecordingGitRunner(static fn(array $command): string => in_array('--is-inside-work-tree', $command, true) ? "false\n" : str_repeat('a', 40));
        $error = captureThrows(fn() => (new ProcessGitWorkingTreeProvider(runner: $runner))->changes(sys_get_temp_dir(), null, 10, 100), NotAWorkTree::class);

        assertSame('Git working-tree query unavailable: not inside a git work tree.', $error->getMessage());
        assertSame([], $runner->commandsWith('diff'));
    }

    public function testAFailedCheckIsNotAWorkTreeWithGitsReason(): void
    {
        $runner = new RecordingGitRunner(static fn(array $command): string => throw new RuntimeException('Git working-tree query unavailable: fatal: not a git repository'));
        $error = captureThrows(fn() => (new ProcessGitWorkingTreeProvider(runner: $runner))->changes(sys_get_temp_dir(), null, 10, 100), NotAWorkTree::class);

        assertSame('Git working-tree query unavailable: fatal: not a git repository', $error->getMessage());
    }

    public function testTheAnswerSaysWhetherHeadNamesACommit(): void
    {
        $born = new RecordingGitRunner(static fn(array $command): string => in_array('--is-inside-work-tree', $command, true) ? "true\n" : (in_array('rev-parse', $command, true) ? str_repeat('c', 40) . "\n" : ''));
        $unborn = new RecordingGitRunner(static fn(array $command): string => in_array('--is-inside-work-tree', $command, true) ? "true\n" : '');

        assertSame(true, (new ProcessGitWorkingTreeProvider(runner: $born))->changes(sys_get_temp_dir(), null, 10, 100)['born'] ?? null);
        assertSame(true, (new ProcessGitWorkingTreeProvider(runner: $born))->changes(sys_get_temp_dir(), 'main', 10, 100)['born'] ?? null);
        assertSame(false, (new ProcessGitWorkingTreeProvider(runner: $unborn))->changes(sys_get_temp_dir(), null, 10, 100)['born'] ?? null);
    }
}
