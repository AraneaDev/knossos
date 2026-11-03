<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Scan\ScanFailureLog;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

final class ScanFailureLogTest extends KnossosTestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/knossos-failure-log-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
        parent::tearDown();
    }

    /** @return array{root: string, started_by: string, class: string, message: string, elapsed_ms: ?int, signal: ?int} */
    private static function entry(string $message = 'boom', string $class = 'other'): array
    {
        return ['root' => '/work/app', 'started_by' => 'pane', 'class' => $class, 'message' => $message, 'elapsed_ms' => 1200, 'signal' => null];
    }

    #[Group('scan')]
    public function testAppendsOneJsonLine(): void
    {
        $path = $this->directory . '/scan-failures.jsonl';
        (new ScanFailureLog($path))->record(self::entry('first', 'killed'));

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        assertSame(1, count($lines));
        $decoded = json_decode($lines[0], true);
        assertSame('first', $decoded['message']);
        assertSame('killed', $decoded['class']);
        assertSame('pane', $decoded['started_by']);
        assertSame('/work/app', $decoded['root']);
        assertSame(1200, $decoded['elapsed_ms']);
        assertSame(true, is_string($decoded['at']) && $decoded['at'] !== '');
    }

    #[Group('scan')]
    public function testKeepsTheLast200(): void
    {
        $path = $this->directory . '/scan-failures.jsonl';
        $log = new ScanFailureLog($path);
        for ($i = 1; $i <= 230; $i++) {
            $log->record(self::entry('failure ' . $i));
        }

        assertSame(200, count(file($path, FILE_IGNORE_NEW_LINES)));
        $recent = $log->recent(500);
        assertSame(200, count($recent));
        assertSame('failure 230', $recent[0]['message']);
        assertSame('failure 31', $recent[199]['message']);
    }

    #[Group('scan')]
    public function testTwoWritersLoseNoLine(): void
    {
        if (!function_exists('pcntl_fork')) {
            return;
        }
        $path = $this->directory . '/scan-failures.jsonl';
        $children = [];
        foreach (['a', 'b'] as $writer) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                $log = new ScanFailureLog($path);
                for ($i = 0; $i < 40; $i++) {
                    $log->record(self::entry($writer . $i));
                }
                exit(0);
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $messages = array_column((new ScanFailureLog($path))->recent(200), 'message');
        assertSame(80, count($messages));
        assertSame(80, count(array_unique($messages)));
    }

    #[Group('scan')]
    public function testReplacesTheLogWholeAndLeavesNoTemporaryFile(): void
    {
        $path = $this->directory . '/scan-failures.jsonl';
        $log = new ScanFailureLog($path);
        $log->record(self::entry('one'));
        $log->record(self::entry('two'));

        assertSame(['scan-failures.jsonl', 'scan-failures.jsonl.lock'], array_values(array_diff(scandir($this->directory), ['.', '..'])));
        assertSame(['two', 'one'], array_column($log->recent(5), 'message'));
    }

    #[Group('scan')]
    public function testAnUnwritablePathDoesNotThrow(): void
    {
        $log = new ScanFailureLog($this->directory . '/missing/deeper/scan-failures.jsonl');
        $log->record(self::entry());
        assertSame([], $log->recent(5));
        (new ScanFailureLog($this->directory))->record(self::entry());
        assertSame(true, true);
    }

    #[Group('scan')]
    public function testRecentIsNewestFirstAndSkipsABrokenLine(): void
    {
        $path = $this->directory . '/scan-failures.jsonl';
        $log = new ScanFailureLog($path);
        $log->record(self::entry('one'));
        file_put_contents($path, "{not json\n", FILE_APPEND);
        $log->record(self::entry('two'));

        $recent = $log->recent(10);
        assertSame(['two', 'one'], array_column($recent, 'message'));
        assertSame(['two'], array_column($log->recent(1), 'message'));
    }

    #[Group('scan')]
    public function testForDatabaseSitesTheLogBesideItAndSkipsAMemoryDatabase(): void
    {
        assertSame(null, ScanFailureLog::forDatabase(':memory:'));
        $log = ScanFailureLog::forDatabase($this->directory . '/knossos.sqlite');
        $log?->record(self::entry());
        assertSame(true, is_file($this->directory . '/scan-failures.jsonl'));
    }
}
