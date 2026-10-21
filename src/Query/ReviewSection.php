<?php

declare(strict_types=1);

namespace Knossos\Query;

/**
 * One section of a change review, with what it adds to the whole answer.
 *
 * A section that could not be evaluated says why in its data and adds no
 * evidence, warnings or truncation.
 */
final readonly class ReviewSection
{
    /**
     * @param array<string, mixed> $data the section as the review reports it, `status` first
     * @param list<array<string, mixed>> $evidence
     * @param list<string> $warnings
     */
    public function __construct(
        public array $data,
        public array $evidence = [],
        public array $warnings = [],
        public bool $truncated = false,
    ) {
    }

    /** A section left out, with the reason. */
    public static function notEvaluated(string $reason): self
    {
        return new self(['status' => 'not_evaluated', 'reason' => $reason]);
    }
}
