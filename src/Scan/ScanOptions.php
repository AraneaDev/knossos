<?php

declare(strict_types=1);

namespace Knossos\Scan;

use Knossos\Cancellation\CancellationToken;

/**
 * What a caller asks of a {@see ProjectScanner::scan()} beyond the root.
 * Every field defaults to "as the project configures it", so a caller names
 * only what it overrides.
 */
final readonly class ScanOptions
{
    /**
     * @param string|null $name the project's display name; null keeps the stored one or the root's basename
     * @param int|null $maxFiles the discovery file cap; null for the configured one
     * @param int|null $maxFileBytes the per-file byte cap; null for the configured one
     * @param list<array<string, mixed>>|null $explicitBoundaries boundary overrides given with the call
     * @param string|null $mode `full` or `incremental`; null lets the planner choose
     * @param CancellationToken|null $cancellation stops the scan between stages; null for none
     * @param int|null $snapshotRetention how many retained snapshots to keep; null for the configured count
     * @param int|null $workerTimeoutMs the language-worker timeout; null for the configured one
     * @param int|null $workerMemoryMb the language-worker memory limit; null for the configured one
     * @param bool $runEnrichers true for an explicit scan, which starts the
     *        project's external enrichers; a background scan (watch, refresh,
     *        hook rescan) leaves it false and carries their last facts over
     * @param ProjectWriterLease|null $lease the project's write lease when the caller already holds it
     */
    public function __construct(
        public ?string $name = null,
        public ?int $maxFiles = null,
        public ?int $maxFileBytes = null,
        public ?array $explicitBoundaries = null,
        public ?string $mode = null,
        public ?CancellationToken $cancellation = null,
        public ?int $snapshotRetention = null,
        public ?int $workerTimeoutMs = null,
        public ?int $workerMemoryMb = null,
        public bool $runEnrichers = false,
        public ?ProjectWriterLease $lease = null,
    ) {}

    /** The same options, scanning under `$lease`. */
    public function withLease(ProjectWriterLease $lease): self
    {
        return new self(
            $this->name,
            $this->maxFiles,
            $this->maxFileBytes,
            $this->explicitBoundaries,
            $this->mode,
            $this->cancellation,
            $this->snapshotRetention,
            $this->workerTimeoutMs,
            $this->workerMemoryMb,
            $this->runEnrichers,
            $lease,
        );
    }
}
