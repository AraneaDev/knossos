<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Scan\ScanFailureLog;
use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertNotFalse;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * A PHP fatal skips every catch block. The pane's rescan still leaves the
 * fatal's message in the failure log, where `knossos doctor` shows it.
 */
final class RescanFatalTest extends KnossosTestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = (string) realpath(sys_get_temp_dir()) . '/knossos-fatal-' . bin2hex(random_bytes(5));
        mkdir($this->directory . '/project/src', 0o700, true);
        mkdir($this->directory . '/data', 0o700, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->directory));
        parent::tearDown();
    }

    /** @return array{0: int, 1: string} the exit status and what the process printed on stdout */
    private function rescan(string $memoryLimit): array
    {
        $project = $this->directory . '/project';
        $process = proc_open(
            [PHP_BINARY, self::repositoryRoot() . '/bin/knossos', 'rescan', $project, '--db=' . $this->directory . '/data/knossos.sqlite', '--json'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['KNOSSOS_MEMORY_LIMIT' => $memoryLimit, 'KNOSSOS_ALLOWED_ROOTS' => $project, 'PATH' => (string) getenv('PATH')],
        );
        assertNotFalse($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);

        return [proc_close($process), $stdout];
    }

    #[Group('query')]
    public function testAMemoryFatalLeavesItsMessageInTheLog(): void
    {
        for ($i = 0; $i < 1500; $i++) {
            $next = ($i + 1) % 1500;
            file_put_contents($this->directory . "/project/src/C{$i}.php", "<?php\nnamespace App;\nfinal class C{$i} { public function a(): void { (new C{$next}())->a(); } }\n");
        }

        [$exit] = $this->rescan('20M');

        assertSame(255, $exit);
        $kept = (new ScanFailureLog($this->directory . '/data/scan-failures.jsonl'))->recent(5);
        assertSame(1, count($kept));
        assertSame('pane', $kept[0]['started_by']);
        assertSame(true, str_contains((string) $kept[0]['message'], 'Allowed memory size'));
    }

    #[Group('query')]
    public function testASuccessfulRescanLogsNothing(): void
    {
        file_put_contents($this->directory . '/project/src/A.php', "<?php\nnamespace App;\nfinal class A {}\n");

        [$exit, $stdout] = $this->rescan('512M');

        assertSame(0, $exit);
        assertSame('ok', json_decode($stdout, true)['status']);
        assertSame(false, is_file($this->directory . '/data/scan-failures.jsonl'));
    }
}
