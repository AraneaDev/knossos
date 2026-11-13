<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit;

use PHPUnit\Framework\Attributes\Group;

/**
 * The file length ratchet refuses a new file past the limit, a listed file
 * that grows past its entry, and an entry left behind once its file is split
 * back within the limit; `--write` records the tree as it is. The tests run
 * the real check against this repository and against a git tree of their own.
 */
#[Group('quality')]
final class FileLengthCheckTest extends KnossosTestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/knossos-file-length-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/src', 0o700, true);
        $this->runFixtureCommand(['git', '-C', $this->directory, 'init', '-q']);
    }

    protected function tearDown(): void
    {
        if ($this->directory !== '' && is_dir($this->directory)) {
            $this->runFixtureCommand(['rm', '-rf', $this->directory]);
        }
    }

    public function testTheRepositoryKeepsToItsOwnBaseline(): void
    {
        [$exit, $output, $errors] = $this->check(self::repositoryRoot(), self::repositoryRoot() . '/file-length-baseline.json');

        assertSame(0, $exit, $errors);
        assertContains('File lengths passed:', $output);
    }

    public function testANewFilePastTheLimitIsRefusedByName(): void
    {
        $this->track('src/Long.php', 11);
        $this->track('src/Short.php', 10);

        [$exit, , $errors] = $this->check($this->directory, $this->baseline(10, []));

        assertSame(1, $exit);
        assertContains('src/Long.php has 11 lines, more than the limit of 10.', $errors);
        assertSame(false, str_contains($errors, 'src/Short.php'));
    }

    public function testAListedFileMayNotGrowPastItsEntry(): void
    {
        $this->track('src/Long.php', 20);

        [$within] = $this->check($this->directory, $this->baseline(10, ['src/Long.php' => 20]));
        assertSame(0, $within);

        $this->track('src/Long.php', 21);
        [$exit, , $errors] = $this->check($this->directory, $this->baseline(10, ['src/Long.php' => 20]));
        assertSame(1, $exit);
        assertContains('src/Long.php has 21 lines, more than the 20 it is listed at.', $errors);
    }

    public function testAnEntryForAFileBackWithinTheLimitOrGoneIsRefused(): void
    {
        $this->track('src/Split.php', 8);

        [$exit, , $errors] = $this->check($this->directory, $this->baseline(10, ['src/Split.php' => 30, 'src/Gone.php' => 40]));

        assertSame(1, $exit);
        assertContains('src/Split.php is listed but has 8 lines, within the limit of 10.', $errors);
        assertContains('src/Gone.php is listed but is no longer a tracked code file.', $errors);
    }

    public function testFixturesAndDeclarationFilesAreNotMeasured(): void
    {
        mkdir($this->directory . '/tests/Fixtures', 0o700, true);
        $this->track('tests/Fixtures/Big.php', 50);
        $this->track('src/types.d.ts', 50);
        $this->track('README.md', 50);

        [$exit, , $errors] = $this->check($this->directory, $this->baseline(10, []));

        assertSame(0, $exit, $errors);
    }

    public function testWriteRecordsTheFilesPastTheLimitAtTheirLength(): void
    {
        $this->track('src/Long.php', 15);
        $this->track('src/Short.php', 5);
        $baseline = $this->baseline(10, ['src/Old.php' => 99]);

        [$exit] = $this->runFixtureCommandOutput([PHP_BINARY, self::repositoryRoot() . '/tools/file-length-check.php', '--root=' . $this->directory, '--baseline=' . $baseline, '--write']);

        assertSame(0, $exit);
        assertSame(['limit' => 10, 'files' => ['src/Long.php' => 15]], json_decode((string) file_get_contents($baseline), true));
        [$after] = $this->check($this->directory, $baseline);
        assertSame(0, $after);
    }

    /** Write `$path` with `$lines` lines and stage it, so git tracks it. */
    private function track(string $path, int $lines): void
    {
        file_put_contents($this->directory . '/' . $path, str_repeat("x\n", $lines));
        $this->runFixtureCommand(['git', '-C', $this->directory, 'add', $path]);
    }

    /**
     * A baseline file of the given limit and entries.
     *
     * @param array<string, int> $files
     */
    private function baseline(int $limit, array $files): string
    {
        $path = $this->directory . '/baseline.json';
        file_put_contents($path, json_encode(['limit' => $limit, 'files' => (object) $files], JSON_THROW_ON_ERROR));

        return $path;
    }

    /** @return array{int, string, string} exit code, output and errors of the check */
    private function check(string $root, string $baseline): array
    {
        return $this->runFixtureCommandOutput([PHP_BINARY, self::repositoryRoot() . '/tools/file-length-check.php', '--root=' . $root, '--baseline=' . $baseline]);
    }
}
