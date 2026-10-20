<?php

declare(strict_types=1);

namespace Knossos\Enrichment;

use Knossos\Scanner\Protocol\Diagnostic;
use Knossos\Scanner\Protocol\ScanContribution;

/**
 * What survived validation of one enricher's output, and what did not.
 *
 * The contribution is safe to hand to reconcile: owned by `enricher:<name>`,
 * namespaced, labelled, and referring only to nodes that exist. Each dropped
 * fact left one warning in $dropped for the scan to report.
 */
final readonly class ValidatedEnrichment
{
    /** @param list<Diagnostic> $dropped one warning per dropped fact */
    public function __construct(
        public ScanContribution $contribution,
        public array $dropped,
    ) {}
}
