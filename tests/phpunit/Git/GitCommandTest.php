<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Git;

use Knossos\Git\GitCommand;
use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

/** The one prefix every git command starts with, and the checks a provider's query passes first. */
final class GitCommandTest extends KnossosTestCase
{
    #[Group('query')]
    public function testEveryCommandSharesOneFlagSet(): void
    {
        assertSame(
            ['git', '-c', 'core.quotePath=false', '--no-optional-locks', '--no-pager', '--literal-pathspecs', '-C', '/somewhere'],
            GitCommand::in('/somewhere'),
        );
    }

    /** A provider's query names the resolved root, and refuses a timeout out of range or a root that is no directory. */
    #[Group('query')]
    public function testACheckedCommandNamesTheResolvedRoot(): void
    {
        $dir = sys_get_temp_dir();

        assertSame(GitCommand::in((string) realpath($dir)), GitCommand::checked($dir . '/.', 5000));
        foreach ([0, 5001] as $timeout) {
            assertSame('timeout_ms must be between 1 and 5000.', self::captureThrown(static fn() => GitCommand::checked($dir, $timeout), RuntimeException::class)->getMessage());
        }
        assertSame('Git project root is not a readable directory.', self::captureThrown(static fn() => GitCommand::checked($dir . '/knossos-no-such-root-' . bin2hex(random_bytes(4)), 100), RuntimeException::class)->getMessage());
        assertSame('Git project root is not a readable directory.', self::captureThrown(static fn() => GitCommand::checked(__FILE__, 100), RuntimeException::class)->getMessage());
    }
}
