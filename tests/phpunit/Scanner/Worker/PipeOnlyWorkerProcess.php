<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner\Worker;

use Knossos\Scanner\Worker\ProcessSupervisorInterface;

/** A worker process whose pipes a test hands in, always running, counting liveness probes. */
final class PipeOnlyWorkerProcess implements ProcessSupervisorInterface
{
    /** @var resource|null */
    public $stdinPipe = null;
    /** @var resource|null */
    public $stdoutPipe = null;
    /** @var resource|null */
    public $stderrPipe = null;

    public bool $started = false;

    /** @var array<string, mixed> fields of the status a test wants the worker to report instead of running */
    public array $statusOverride = [];

    /** Number of liveness probes, i.e. completed readMessage() loop iterations. */
    public int $statusChecks = 0;

    public function start(): void
    {
        $this->started = true;
    }

    public function isRunning(): bool
    {
        return true;
    }

    /** @return resource|null */
    public function stdin()
    {
        return $this->stdinPipe;
    }

    /** @return resource|null */
    public function stdout()
    {
        return $this->stdoutPipe;
    }

    /** @return resource|null */
    public function stderr()
    {
        return $this->stderrPipe;
    }

    /** @return array{command: string, pid: int, running: bool, signaled: bool, stopped: bool, exitcode: int, termsig: int, stopsig: int} */
    public function status(): array
    {
        ++$this->statusChecks;

        return $this->statusOverride + [
            'command' => '',
            'pid' => 0,
            'running' => true,
            'signaled' => false,
            'stopped' => false,
            'exitcode' => -1,
            'termsig' => 0,
            'stopsig' => 0,
        ];
    }

    public function close(bool $terminate): void
    {
        $this->stdinPipe = null;
        $this->stdoutPipe = null;
        $this->stderrPipe = null;
    }
}
