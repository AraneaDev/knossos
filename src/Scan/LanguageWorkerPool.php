<?php

declare(strict_types=1);

namespace Knossos\Scan;

use Knossos\Scanner\Worker\{ProcessScannerClient, WorkerExecutionPolicy};
use Throwable;

/**
 * NOT marked `final`: PHPUnit 12.5's createMock does not honour the PHPDoc
 * @final annotation (only the language-level final keyword), so this class
 * must be non-final for direct mocking in PHPUnit. Callers should treat it as
 * semantically final — there is no use case for subclassing it.
 */
class LanguageWorkerPool
{
    /** @var array<string, ProcessScannerClient> */
    private array $clients = [];
    private ?int $timeoutMs = null;
    private ?int $memoryMb = null;
    private bool $policySeen = false;
    /** @var array<string, string> The hash of the worker code each running client was started from, by language. */
    private array $codeHashes = [];

    /**
     * Discard workers started under a different execution policy.
     *
     * A ProjectScanService lives longer than one scan (the MCP server holds a
     * single instance), so a cached worker can outlast the settings it was
     * launched with. The memory cap is baked into the worker's command line by
     * LanguageDescriptor::withMemoryMb(), so a changed cap only takes effect on
     * a freshly spawned process.
     */
    public function prepare(WorkerExecutionPolicy $policy): void
    {
        if ($this->policySeen && ($this->timeoutMs !== $policy->requestTimeoutMs || $this->memoryMb !== $policy->workerMemoryMb)) {
            $this->shutdown();
        }
        $this->timeoutMs = $policy->requestTimeoutMs;
        $this->memoryMb = $policy->workerMemoryMb;
        $this->policySeen = true;
    }

    /**
     * The running client for a language, started on demand.
     *
     * A scan keys its cache by the worker's code as it is on disk
     * (`$codeHash`). The MCP server keeps workers between scans, so a worker
     * started before that code changed would answer with the old code under
     * the new key, and the cache would keep those facts for good: a changed
     * hash replaces the worker first. Without a hash the client is reused.
     */
    public function client(LanguageDescriptor $descriptor, WorkerExecutionPolicy $policy, ?string $codeHash = null): ProcessScannerClient
    {
        $this->prepare($policy);
        if ($codeHash !== null) {
            $startedFrom = $this->codeHashes[$descriptor->key] ?? null;
            if ($startedFrom !== null && $startedFrom !== $codeHash) {
                $this->stop($descriptor->key);
            }
            $this->codeHashes[$descriptor->key] = $codeHash;
        }
        $adjusted = $descriptor->withMemoryMb($policy->workerMemoryMb);
        return $this->clients[$descriptor->key] ??= new ProcessScannerClient($adjusted->command, $policy->limits());
    }

    /**
     * Replace one language's worker, leaving the other languages' alone.
     *
     * A request that ends in a WorkerException closes its session, so the
     * cached client is unusable even though the failure was retryable. Only the
     * affected language pays: a full shutdown would also discard, for instance,
     * the TypeScript worker's program cache.
     */
    public function restart(LanguageDescriptor $descriptor, WorkerExecutionPolicy $policy): ProcessScannerClient
    {
        $this->stop($descriptor->key);

        return $this->client($descriptor, $policy);
    }

    /** Stop one language's worker, if it runs; the next client() starts a fresh one. */
    private function stop(string $key): void
    {
        $client = $this->clients[$key] ?? null;
        unset($this->clients[$key]);
        if ($client !== null) {
            try {
                $client->shutdown();
            } catch (Throwable) {
                // Already dead, which is the usual reason for restarting it.
            }
        }
    }

    /** Stop every worker; called on the way out of a scan, including a failed one. */
    public function shutdown(): void
    {
        foreach ($this->clients as $client) {
            try {
                $client->shutdown();
            } catch (Throwable) {
            }
        }
        $this->clients = [];
        $this->codeHashes = [];
        $this->timeoutMs = null;
        $this->memoryMb = null;
        $this->policySeen = false;
    }
}
