<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Filesystem;

use Knossos\Filesystem\AtomicFile;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A whole-file replacement lands with the mode it was asked for, before it is
 * in place, and a failed one leaves the target and the directory as they were.
 */
#[Group('filesystem')]
final class AtomicFileTest extends KnossosTestCase
{
    private string $directory;

    private int $umask;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0o700, true);
        // A permissive umask, so a mode the writer does not set would show.
        $this->umask = umask(0o022);
    }

    protected function tearDown(): void
    {
        umask($this->umask);
        @chmod($this->directory, 0o700);
        $this->removeTempTree($this->directory);
        parent::tearDown();
    }

    public function testAJsonWriteReadsBackWithATrailingNewline(): void
    {
        $path = $this->directory . '/state.json';

        assertSame(true, AtomicFile::writeJson($path, ['roots' => ['/srv/a']]));

        assertSame("{\n    \"roots\": [\n        \"/srv/a\"\n    ]\n}\n", file_get_contents($path));
        assertSame(['state.json'], $this->entries());
    }

    public function testAModeIsAppliedEvenUnderAPermissiveUmask(): void
    {
        $path = $this->directory . '/secret.json';
        file_put_contents($path, '{}');
        chmod($path, 0o644);

        AtomicFile::writeJson($path, ['a' => 1], 0o600);

        assertSame(0o600, $this->mode($path));
    }

    public function testWithoutAModeANewFileFollowsTheUmask(): void
    {
        $path = $this->directory . '/plain.json';

        AtomicFile::write($path, 'x');

        assertSame(0o644, $this->mode($path));
    }

    public function testPreservingKeepsTheModeOfAnExistingFile(): void
    {
        $path = $this->directory . '/kept.json';
        file_put_contents($path, '{}');
        chmod($path, 0o640);

        AtomicFile::writeJson($path, ['a' => 1], 0o600, true);

        assertSame(0o640, $this->mode($path));
        assertSame(['a' => 1], json_decode((string) file_get_contents($path), true));
    }

    public function testPreservingAppliesTheModeToAFileCreatedForTheFirstTime(): void
    {
        $path = $this->directory . '/new.json';

        AtomicFile::writeJson($path, ['a' => 1], 0o600, true);

        assertSame(0o600, $this->mode($path));
    }

    public function testAFailedRenameLeavesNoTemporaryFileAndTheTargetIntact(): void
    {
        $path = $this->directory . '/occupied';
        mkdir($path . '/inside', 0o700, true);

        assertSame(false, AtomicFile::write($path, 'x', 0o600));

        assertSame(true, is_dir($path . '/inside'));
        assertSame(['occupied'], $this->entries());
    }

    public function testAMissingDirectoryFailsWithoutCreatingIt(): void
    {
        assertSame(false, AtomicFile::write($this->directory . '/missing/file.json', 'x'));

        assertSame([], $this->entries());
    }

    /** @return list<string> */
    private function entries(): array
    {
        return array_values(array_diff((array) scandir($this->directory), ['.', '..']));
    }

    private function mode(string $path): int
    {
        clearstatcache(true, $path);

        return fileperms($path) & 0o777;
    }
}
