<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The memory limit the host puts on the PHP worker's command line is the one
 * it runs under. A fixed ini_set in the worker once overrode it, so
 * limits.worker_memory_mb raised nothing.
 */
final class PhpWorkerMemoryLimitTest extends KnossosTestCase
{
    #[Group('scan')]
    public function testTheCommandLineLimitIsTheOneTheWorkerRunsUnder(): void
    {
        self::assertSame('300M', $this->limitTheWorkerRunsUnder('300M'));
    }

    /** A run with no cap at all, as under a php.ini without one, still gets the 512M default. */
    #[Group('scan')]
    public function testAnUncappedRunGetsTheDefaultCap(): void
    {
        self::assertSame('512M', $this->limitTheWorkerRunsUnder('-1'));
    }

    /** Start the worker with this memory_limit, close its input, and read back the limit it ended under. */
    private function limitTheWorkerRunsUnder(string $limit): string
    {
        $directory = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($directory, 0o700, true);
        try {
            $record = $directory . '/limit.txt';
            $prepend = $directory . '/prepend.php';
            file_put_contents($prepend, '<?php register_shutdown_function(static fn() => file_put_contents(' . var_export($record, true) . ", (string) ini_get('memory_limit')));\n");
            $process = proc_open(
                [PHP_BINARY, '-d', 'memory_limit=' . $limit, '-d', 'auto_prepend_file=' . $prepend, self::repositoryRoot() . '/workers/php/bin/worker'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            self::assertIsResource($process);
            fclose($pipes[0]);
            stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            proc_close($process);

            return (string) @file_get_contents($record);
        } finally {
            $this->removeTempTree($directory);
        }
    }
}
