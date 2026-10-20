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
    /** One of `missing_command`, `no_enrichment_capability`, `timeout`, `protocol_error` or `exit_nonzero`. */
    public const REASONS = ['missing_command', 'no_enrichment_capability', 'timeout', 'protocol_error', 'exit_nonzero'];

    private function __construct(
        public bool $ok,
        public string $name,
        public ?ScanContribution $raw,
        public ?string $reason,
    ) {}

    /** The enricher answered with a contribution that decoded. */
    public static function succeeded(string $name, ScanContribution $raw): self
    {
        return new self(true, $name, $raw, null);
    }

    /** The enricher could not be run or did not answer usably; $reason is one of {@see REASONS}. */
    public static function failed(string $name, string $reason): self
    {
        return new self(false, $name, null, $reason);
    }
}
