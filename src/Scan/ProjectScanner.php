<?php

declare(strict_types=1);

namespace Knossos\Scan;

use Knossos\Result\ResultEnvelope;

/**
 * Scan entry point consumed by orchestrators such as watch mode. Extracted so
 * long-running callers can be exercised against controlled scan outcomes.
 */
interface ProjectScanner
{
    /** Build or refresh a project's graph and return the result envelope. */
    public function scan(string $root, ScanOptions $options = new ScanOptions()): ResultEnvelope;
}
