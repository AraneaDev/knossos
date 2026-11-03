<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Runtime;

use InvalidArgumentException;
use Knossos\Runtime\PaneAutostart;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The per-project switch that opens the pane when a session starts.
 *
 * Read on every session start, so the read path must survive any file it finds.
 */
final class PaneAutostartTest extends KnossosTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/knossos-autostart-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0o755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    #[Group('runtime')]
    public function testAbsentFileMeansOff(): void
    {
        assertSame(false, $this->autostart()->isOn('/srv/a'));
    }

    #[Group('runtime')]
    public function testOnThenOff(): void
    {
        $autostart = $this->autostart();

        $autostart->set('/srv/a', true);
        assertSame(true, $autostart->isOn('/srv/a'));
        assertSame(['roots' => ['/srv/a']], json_decode((string) file_get_contents($this->file()), true));

        $autostart->set('/srv/a', false);
        assertSame(false, $autostart->isOn('/srv/a'));
    }

    #[Group('runtime')]
    public function testOneRootDoesNotAffectAnother(): void
    {
        $autostart = $this->autostart();
        $autostart->set('/srv/a', true);
        $autostart->set('/srv/b', true);
        $autostart->set('/srv/a', false);

        assertSame(false, $autostart->isOn('/srv/a'));
        assertSame(true, $autostart->isOn('/srv/b'));
    }

    #[Group('runtime')]
    public function testASubfolderReadsItsToplevel(): void
    {
        $repo = (string) realpath($this->dir) . '/repo';
        mkdir($repo . '/.git', 0o755, true);
        mkdir($repo . '/src', 0o755, true);
        $autostart = $this->autostart();
        $autostart->set($repo, true);

        assertSame(true, $autostart->isOn($repo . '/src'));
    }

    #[Group('runtime')]
    public function testACorruptFileReadsAsOffAndSetRefuses(): void
    {
        $bytes = '{"roots": [';
        file_put_contents($this->file(), $bytes);
        $autostart = $this->autostart();

        assertSame(false, $autostart->isOn('/srv/a'));
        $error = captureThrows(fn() => $autostart->set('/srv/a', true), InvalidArgumentException::class);
        assertSame(true, str_contains($error->getMessage(), 'is not valid JSON'));
        assertSame($bytes, (string) file_get_contents($this->file()));
    }

    #[Group('runtime')]
    public function testTheFileIsWrittenAtomically(): void
    {
        $this->autostart()->set('/srv/a', true);

        assertSame(['pane-autostart.json'], array_values(array_diff((array) scandir($this->dir), ['.', '..'])));
    }

    private function file(): string
    {
        return $this->dir . '/pane-autostart.json';
    }

    private function autostart(): PaneAutostart
    {
        return new PaneAutostart($this->file());
    }
}
