<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Support;

use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class ScratchTest extends KnossosTestCase
{
    /** Paths a test asks for share one root per test, which lives in the run's temp directory. */
    public function testScratchPathsOfOneTestShareARootInTheRunsTempDirectory(): void
    {
        $directory = self::scratchDirectory('knossos-probe');
        $file = self::scratchFile('knossos-probe');
        $path = self::scratchPath('knossos-probe');

        self::assertDirectoryExists($directory);
        self::assertFileExists($file);
        self::assertFileDoesNotExist($path);
        self::assertSame(dirname($directory), dirname($file));
        self::assertSame(dirname($directory), dirname($path));
        self::assertStringStartsWith('ScratchTest-', basename(dirname($directory)));
        self::assertSame(realpath(sys_get_temp_dir()), dirname($directory, 2));
        self::assertStringStartsWith('knossos-probe-', basename($path));
    }

    /** The root of the previous test is gone before the next test starts: the after-test hook removed it. */
    public function testTheRootOfOneTestIsRemovedBeforeTheNext(): void
    {
        $first = dirname(self::scratchPath());
        self::assertDirectoryExists($first);

        $this->removeScratchOfTest();
        $this->enterScratchOfTest();

        self::assertDirectoryDoesNotExist($first);
        self::assertNotSame($first, dirname(self::scratchPath()));
    }

    /**
     * A directory a test made unreadable, and a read-only file in it, are
     * removed; a link is removed as a link, and what it points at is kept.
     */
    public function testRemoveGivesPermissionsBackAndNeverFollowsALink(): void
    {
        $outside = self::scratchDirectory('knossos-outside');
        file_put_contents($outside . '/kept.txt', 'kept');
        $tree = self::scratchDirectory('knossos-tree');
        mkdir($tree . '/locked/deeper', 0o700, true);
        file_put_contents($tree . '/locked/deeper/read-only.txt', 'x');
        chmod($tree . '/locked/deeper/read-only.txt', 0o400);
        symlink($outside, $tree . '/locked/outside');
        symlink('gone', $tree . '/dangling');
        chmod($tree . '/locked/deeper', 0o000);
        chmod($tree . '/locked', 0o000);

        Scratch::remove($tree);

        self::assertFalse(file_exists($tree) || is_link($tree));
        self::assertSame('kept', file_get_contents($outside . '/kept.txt'));
        Scratch::remove($tree . '/never-made');
    }

    /** The guard names what is left in the run's root, and only the process that made it checks it. */
    public function testTheGuardNamesWhatIsLeftAndOnlyInTheOwningProcess(): void
    {
        $root = self::scratchDirectory('knossos-run');
        mkdir($root . '/home');
        touch($root . '/phpunit_Ab12Cd');

        self::assertNull(TempRootGuard::leftover($root, '41', 41));

        mkdir($root . '/LeakyTest-0011aabb');
        touch($root . '/knossos-left.sqlite');
        $message = (string) TempRootGuard::leftover($root, '41', 41);

        self::assertStringContainsString('2 entries', $message);
        self::assertStringContainsString("\n  LeakyTest-0011aabb\n  knossos-left.sqlite", $message);
        self::assertStringNotContainsString('phpunit_', $message);
        self::assertNull(TempRootGuard::leftover($root, '41', 42));
        self::assertNull(TempRootGuard::leftover(false, false, 41));
    }
}
