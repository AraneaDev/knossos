<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ProjectFile;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/** A name is a project file only when it stays inside the project. */
final class ProjectFileTest extends KnossosTestCase
{
    #[Group('query')]
    public function testOnlyARegularFileInsideTheRootCounts(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        $outside = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($root . '/tools', 0o700, true);
        mkdir($outside, 0o700, true);
        try {
            file_put_contents($root . '/tools/quality', "#!/bin/sh\n");
            file_put_contents($outside . '/secret', "x\n");
            symlink($outside . '/secret', $root . '/tools/link');

            self::assertTrue(ProjectFile::exists($root, 'tools/quality'));
            self::assertFalse(ProjectFile::exists($root, 'tools'), 'A directory is not a file.');
            self::assertFalse(ProjectFile::exists($root, 'tools/missing'));
            self::assertFalse(ProjectFile::exists($root, '/etc/passwd'), 'An absolute path is not project-relative.');
            self::assertFalse(ProjectFile::exists($root, 'tools/../tools/quality'), 'No parent segments.');
            self::assertFalse(ProjectFile::exists($root, 'tools/link'), 'A symlink out of the project is not its file.');
            self::assertFalse(ProjectFile::exists('', 'tools/quality'));
            self::assertFalse(ProjectFile::exists($root, ''));
            // These once reached realpath(), which throws on a NUL byte.
            self::assertFalse(ProjectFile::exists($root, "tools/quality\0"), 'A NUL byte names no file.');
            self::assertFalse(ProjectFile::exists($root, 'C:/tools/quality'), 'A drive letter is not project-relative.');
        } finally {
            $this->removeTempTree($root);
            $this->removeTempTree($outside);
        }
    }
}
