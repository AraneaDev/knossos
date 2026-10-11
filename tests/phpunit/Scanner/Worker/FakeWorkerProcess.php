<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner\Worker;

use Knossos\Scanner\Worker\ProcessSupervisorInterface;

/** A worker process on in-memory pipes, whose liveness and exit a test sets. */
final class FakeWorkerProcess implements ProcessSupervisorInterface
{
    /** @var array<int, resource> */
    public array $pipes = [];

    public bool $started = false;
    public bool $running = true;
    // How the process ended, so a test can distinguish a worker that
    // chose its exit code from one something else killed.
    public bool $signaled = false;
    public int $termsig = 0;
    public int $exitcode = -1;

    public function start(): void
    {
        $this->started = true;
        if (!isset($this->pipes[0])) {
            $this->pipes[0] = fopen('php://temp', 'r+');
            $this->pipes[1] = fopen('php://temp', 'r+');
            $this->pipes[2] = fopen('php://temp', 'r+');
        }
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function stdin()
    {
        $this->start();
        return $this->pipes[0];
    }

    public function stdout()
    {
        $this->start();
        return $this->pipes[1];
    }

    public function stderr()
    {
        $this->start();
        return $this->pipes[2];
    }

    /** @return array{command: string, pid: int, running: bool, signaled: bool, stopped: bool, exitcode: int, termsig: int, stopsig: int} */
    public function status(): array
    {
        return [
            'command' => '',
            'pid' => 0,
            'running' => $this->running,
            'signaled' => $this->signaled,
            'stopped' => false,
            'exitcode' => $this->exitcode,
            'termsig' => $this->termsig,
            'stopsig' => 0,
        ];
    }

    public function close(bool $terminate): void
    {
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $this->pipes = [];
        $this->running = false;
    }
}
