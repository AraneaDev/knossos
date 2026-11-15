<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Support;

trait TempTrees
{
    public function copyTree(string $from, string $to): void
    {
        mkdir($to, 0o777, true);
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS)) as $item) {
            $rel = substr($item->getPathname(), strlen($from) + 1);
            $dest = $to . '/' . $rel;
            if ($item->isDir()) {
                @mkdir($dest, 0o777, true);
                continue;
            }
            @mkdir(dirname($dest), 0o777, true);
            copy($item->getPathname(), $dest);
        }
    }

    /**
     * Pushes every directory under $root's mtime $seconds into the past.
     *
     * WalkDriftOracle's addedSince() flags an addition when a tracked file's
     * directory mtime reads later than the scan's finished_at. Those are two
     * separate clock reads (a later stat() vs. an earlier DB write) that are
     * not guaranteed to agree on which integer second an instant rounds to,
     * so a directory populated by copyTree() moments before a scan can read
     * in the same second as, or after, that scan's own finished_at. Backdating
     * directories (not finished_at) removes that ambiguity without moving the
     * timestamp callers legitimately compare "now" against.
     */
    public function backdateDirectories(string $root, int $seconds): void
    {
        touch($root, filemtime($root) - $seconds);
        foreach (scandir($root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $root . '/' . $entry;
            if (is_dir($path)) {
                $this->backdateDirectories($path, $seconds);
            }
        }
    }

    /**
     * Removes a tree seedProjectWithFiles() or scanTempFixture() made, before
     * the test is over; the test's scratch root goes afterwards anyway.
     */
    public function removeTempTree(string $root): void
    {
        self::removeOwnTree($root, 'knossos-stale-');
    }

    /** Removes an incremental-scan fixture tree before the test is over. */
    public function removeFixtureTree(string $root): void
    {
        self::removeOwnTree($root, 'knossos-incremental-');
    }

    /** @param non-empty-list<string> $command */
    public function runFixtureCommand(array $command): void
    {
        [$exit, $stdout, $stderr] = $this->runFixtureCommandOutput($command);
        if ($exit !== 0) {
            throw new \RuntimeException('Fixture command failed: ' . trim($stdout . ' ' . $stderr));
        }
    }

    /** @param non-empty-list<string> $command @return array{0: int, 1: string, 2: string} */
    public function runFixtureCommandOutput(array $command): array
    {
        $pipes = [];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to start fixture command.');
        }
        fclose($pipes[0]);

        // Drain stdout and stderr together. Reading one to EOF before touching
        // the other deadlocks as soon as the child fills the pipe nobody is
        // reading (64 KiB on Linux): the child blocks on write, we block on read.
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $buffers = [1 => '', 2 => ''];
        $open = [1 => $pipes[1], 2 => $pipes[2]];
        while ($open !== []) {
            $read = array_values($open);
            $write = null;
            $except = null;
            if (stream_select($read, $write, $except, null) === false) {
                break;
            }
            foreach ($open as $key => $stream) {
                if (!in_array($stream, $read, true)) {
                    continue;
                }
                $chunk = fread($stream, 8192);
                if ($chunk === false || $chunk === '') {
                    if (feof($stream)) {
                        fclose($stream);
                        unset($open[$key]);
                    }
                    continue;
                }
                $buffers[$key] .= $chunk;
            }
        }
        foreach ($open as $stream) {
            fclose($stream);
        }

        return [proc_close($process), $buffers[1], $buffers[2]];
    }

    /** Removes a git fixture tree before the test is over. */
    public function removeGitFixture(string $root): void
    {
        self::removeOwnTree($root, 'knossos-git-');
    }

    /**
     * Removes $root, which must be a fixture of the kind $prefix names under
     * the run's temp directory: a path from anywhere else is refused, so a
     * wrong argument cannot remove something the test did not make.
     */
    private static function removeOwnTree(string $root, string $prefix): void
    {
        $temp = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
        if (!str_starts_with($root, rtrim($temp, '/') . '/') || !str_starts_with(basename($root), $prefix) || str_contains($root, '/../')) {
            throw new \RuntimeException('Refusing to remove an unexpected fixture path.');
        }
        Scratch::remove($root);
    }
}
