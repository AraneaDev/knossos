<?php

declare(strict_types=1);

namespace Knossos\Scan;

use Knossos\Cancellation\CancellationToken;
use Knossos\Scanner\Worker\WorkerExecutionPolicy;

/**
 * What stays the same across one language's batches while
 * {@see LanguageScanRunner} sends them: the language and its worker policy,
 * the request every batch extends, the cancellation, the worker's scanner id,
 * and the queue and left-out set a failed batch updates.
 */
final readonly class LanguageBatchRun
{
    /**
     * @param array<string, mixed> $request the request each batch adds its `files` to
     */
    public function __construct(
        public LanguageDescriptor $descriptor,
        public WorkerExecutionPolicy $policy,
        public array $request,
        public CancellationToken $cancellation,
        public string $scannerId,
        public ScanBatchQueue $queue,
        public LeftOutFiles $leftOut,
    ) {}
}
