<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Roots;

use Knossos\Roots\RootsFile;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

final class RootsFileTest extends KnossosTestCase
{
    /** A moved checkout swaps its old grant for the new one in one write, keeping every other root. */
    #[Group('discovery')]
    public function testReplaceSwapsOneRootAndKeepsTheRest(): void
    {
        $directory = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($directory, 0o700, true);
        try {
            $file = new RootsFile($directory . '/roots.json');
            $file->write(['/srv/other', '/root/project/']);

            self::assertSame(['added' => true, 'removed' => true], $file->replace('/root/project', '/home/me/project'));
            self::assertSame(['/srv/other', '/home/me/project'], $file->read());
            self::assertSame(['added' => false, 'removed' => false], $file->replace('/root/project', '/home/me/project'), 'A second run has nothing left to do.');
        } finally {
            $this->removeTempTree($directory);
        }
    }

    /** No old entry to drop (a roots file that never listed it) still adds the new root. */
    #[Group('discovery')]
    public function testReplaceWithNothingToRemoveOnlyAdds(): void
    {
        $directory = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($directory, 0o700, true);
        try {
            $file = new RootsFile($directory . '/roots.json');

            self::assertSame(['added' => true, 'removed' => false], $file->replace(null, '/home/me/project'));
            self::assertSame(['/home/me/project'], $file->read());
        } finally {
            $this->removeTempTree($directory);
        }
    }

    /** A file that parses but holds no list is refused rather than read as empty and overwritten. */
    #[Group('discovery')]
    public function testAFileWithTheWrongShapeIsRefused(): void
    {
        $directory = $this->directory();
        try {
            file_put_contents($directory . '/roots.json', '{"roots": 5}');

            $error = captureThrows(fn() => (new RootsFile($directory . '/roots.json'))->read(), \InvalidArgumentException::class);

            self::assertStringContainsString('does not have the expected {"roots": [...]} shape', $error->getMessage());
        } finally {
            $this->removeTempTree($directory);
        }
    }

    /** A parent directory that cannot be created is reported, not left as a half-written grant. */
    #[Group('discovery')]
    public function testAParentThatCannotBeCreatedIsReported(): void
    {
        $directory = $this->directory();
        try {
            file_put_contents($directory . '/blocker', '');

            $error = captureThrows(fn() => (new RootsFile($directory . '/blocker/sub/roots.json'))->write(['/srv']), \InvalidArgumentException::class);

            self::assertStringStartsWith('Unable to create', $error->getMessage());
        } finally {
            $this->removeTempTree($directory);
        }
    }

    /** A roots file nobody may read is an error, never an empty allow-list to append to. */
    #[Group('discovery')]
    public function testAnUnreadableFileIsReported(): void
    {
        self::skipWhenRoot();
        $directory = $this->directory();
        try {
            file_put_contents($directory . '/roots.json', '{"roots": ["/srv"]}');
            chmod($directory . '/roots.json', 0o000);

            $error = captureThrows(fn() => @(new RootsFile($directory . '/roots.json'))->read(), \InvalidArgumentException::class);

            self::assertStringStartsWith('Unable to read', $error->getMessage());
        } finally {
            @chmod($directory . '/roots.json', 0o600);
            $this->removeTempTree($directory);
        }
    }

    /** A roots file created for the first time is owner-only, like the directory it lives in, whatever the umask. */
    #[Group('discovery')]
    public function testAFirstWriteIsOwnerOnly(): void
    {
        $directory = $this->directory();
        $umask = umask(0o022);
        try {
            (new RootsFile($directory . '/roots.json'))->write(['/srv']);

            clearstatcache();
            self::assertSame(0o600, fileperms($directory . '/roots.json') & 0o777);
        } finally {
            umask($umask);
            $this->removeTempTree($directory);
        }
    }

    /** A rewrite keeps the mode the operator gave the file. */
    #[Group('discovery')]
    public function testARewriteKeepsTheFilesMode(): void
    {
        $directory = $this->directory();
        try {
            $file = new RootsFile($directory . '/roots.json');
            $file->write(['/srv']);
            chmod($directory . '/roots.json', 0o640);

            $file->write(['/srv', '/home']);

            clearstatcache();
            self::assertSame(0o640, fileperms($directory . '/roots.json') & 0o777);
        } finally {
            $this->removeTempTree($directory);
        }
    }

    /** A directory the write cannot land in leaves no temporary file behind and the old file intact. */
    #[Group('discovery')]
    public function testAnUnwritableDirectoryLeavesTheFileIntact(): void
    {
        self::skipWhenRoot();
        $directory = $this->directory();
        try {
            $file = new RootsFile($directory . '/roots.json');
            $file->write(['/srv']);
            chmod($directory, 0o500);

            $error = captureThrows(fn() => @$file->write(['/srv', '/home']), \InvalidArgumentException::class);

            chmod($directory, 0o700);
            self::assertStringStartsWith('Unable to write', $error->getMessage());
            self::assertSame(['/srv'], $file->read());
            self::assertSame(['roots.json'], array_values(array_diff((array) scandir($directory), ['.', '..'])));
        } finally {
            @chmod($directory, 0o700);
            $this->removeTempTree($directory);
        }
    }

    private function directory(): string
    {
        $directory = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($directory, 0o700, true);

        return $directory;
    }

    /** Permission bits do not bind root, so these cases cannot be staged there. */
    private static function skipWhenRoot(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('Permission bits do not restrict root.');
        }
    }
}
