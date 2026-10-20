<?php

declare(strict_types=1);

namespace Knossos\Configuration;

/** A project's resolved `knossos.json` settings: limits, ignores, boundaries, frameworks, web roots. */
final readonly class ProjectConfiguration
{
    /**
     * @param list<string> $ignores
     * @param list<array<string, mixed>> $boundaries
     * @param list<string> $frameworks
     * @param list<array<string, mixed>> $policies
     * @param array<string, int> $qualityBudgets
     * @param list<string> $deadCodeSuppressions
     * @param int|null $watchScanTimeoutMs how long one scan of the shared watcher may run; null for its default
     * @param int|null $refreshBudgetMs how long refresh_if_stale may rescan inside a query, 0 for never; null for its default
     * @param array<string, string> $webRoots URL path prefix (`/static/`) => the project folder a web server serves it from (`web/public/`)
     * @param list<string> $enrichers names of installation-defined enrichers the project asks for
     */
    public function __construct(
        public ?string $path = null,
        public array $ignores = [],
        public ?int $maxFiles = null,
        public ?int $maxFileBytes = null,
        public ?int $workerTimeoutMs = null,
        public ?int $workerMemoryMb = null,
        public array $boundaries = [],
        public array $frameworks = [],
        public ?int $snapshotRetention = null,
        public array $policies = [],
        public array $qualityBudgets = [],
        public array $deadCodeSuppressions = [],
        public ?int $watchScanTimeoutMs = null,
        public ?int $refreshBudgetMs = null,
        public array $webRoots = [],
        public array $enrichers = [],
    ) {}
}
