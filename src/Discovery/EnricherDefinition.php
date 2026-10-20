<?php

declare(strict_types=1);

namespace Knossos\Discovery;

/**
 * One external enricher an installation defines in `enrichers.json`: the
 * argv to start, how long one run may take, and the native node kinds it
 * wants sent as input.
 *
 * A project only ever names an enricher; the command lives here, on the
 * installation side, so a repository can request but never supply one.
 */
final readonly class EnricherDefinition
{
    /** What an enricher name may look like; a path, a command line or a flag never matches. */
    public const NAME_PATTERN = '/^[a-z][a-z0-9_-]{0,63}$/';

    /** How long one whole run may take, from start to answer, not each protocol request. */
    public const DEFAULT_TIMEOUT_SECONDS = 30;
    public const MIN_TIMEOUT_SECONDS = 1;
    public const MAX_TIMEOUT_SECONDS = 120;

    /**
     * @param non-empty-list<string> $command argv, started without a shell
     * @param array<string, string> $environment extra environment for the process
     * @param list<string> $inputNodeKinds native node kinds sent to the enricher as input
     */
    public function __construct(
        public string $name,
        public array $command,
        public int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        public array $environment = [],
        public array $inputNodeKinds = [],
    ) {}
}
