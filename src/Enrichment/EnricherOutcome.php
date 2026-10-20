<?php

declare(strict_types=1);

namespace Knossos\Enrichment;

use Knossos\Scanner\Protocol\ScanContribution;

/**
 * How one enricher run ended: its decoded, not yet validated, contribution, or why there is none.
 *
 * A failure is a value rather than an exception because an enricher never
 * fails a scan; the scan carries the enricher's previous facts over instead.
 */
final readonly class EnricherOutcome
{
    /** Why a run failed; `exited_early` is a clean exit 0 before the enricher answered, `exit_nonzero` any other exit. */
    public const REASONS = ['missing_command', 'no_enrichment_capability', 'timeout', 'protocol_error', 'exit_nonzero', 'exited_early', 'cancelled'];

    /** @param ?string $detail for a failure, the first line of what went wrong, short enough for a diagnostic */
    private function __construct(
        public bool $ok,
        public string $name,
        public ?ScanContribution $raw,
        public ?string $reason,
        public ?string $detail = null,
    ) {}

    /** The enricher answered with a contribution that decoded. */
    public static function succeeded(string $name, ScanContribution $raw): self
    {
        return new self(true, $name, $raw, null);
    }

    /** The enricher could not be run or did not answer usably; $reason is one of {@see REASONS}. */
    public static function failed(string $name, string $reason, ?string $detail = null): self
    {
        return new self(false, $name, null, $reason, $detail);
    }
}
