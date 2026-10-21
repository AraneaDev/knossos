<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use InvalidArgumentException;
use Knossos\Query\ProjectRelativePath;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** Every spelling of a path inside the project names one relative path; one outside names none. */
#[Group('query')]
final class ProjectRelativePathTest extends TestCase
{
    public function testSpellingsOfOneFileAgree(): void
    {
        self::assertSame('src/x.php', ProjectRelativePath::of('/repo', 'src/x.php'));
        self::assertSame('src/x.php', ProjectRelativePath::of('/repo', './src/x.php'));
        self::assertSame('src/x.php', ProjectRelativePath::of('/repo', '/repo/src/x.php'));
        self::assertSame('src/x.php', ProjectRelativePath::of('/repo', 'src\\x.php'));
    }

    /** A NUL byte and a drive letter once passed, being neither absolute nor `..`. */
    public function testAPathLeavingTheProjectIsRefused(): void
    {
        foreach (['/elsewhere/x.php', '../x.php', 'src/../../x.php', '', "src/x\0.php", 'C:/src/x.php'] as $path) {
            try {
                ProjectRelativePath::of('/repo', $path);
                self::fail('Accepted ' . $path);
            } catch (InvalidArgumentException $error) {
                self::assertStringContainsString('outside the project', $error->getMessage());
            }
        }
    }
}
