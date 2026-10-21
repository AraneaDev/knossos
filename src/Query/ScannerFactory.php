<?php

declare(strict_types=1);

namespace Knossos\Query;

use Knossos\Configuration\EnrichersFile;
use Knossos\Roots\AllowedRoots;
use Knossos\Scan\ProjectScanService;
use PDO;

/**
 * Builds the scanners every writer of one graph database uses.
 *
 * One place that wires a scan to the installation's workers and to the
 * enrichers file beside the database, and that puts it behind the
 * {@see LedgeredScanner}: a writer built anywhere else is how a scan slips
 * past the scan ledger, and a turn brief or branch gate then cannot account
 * for it.
 */
final readonly class ScannerFactory
{
    private EnrichersFile $enrichers;

    /**
     * @param PDO $pdo the graph database the scans write
     * @param string $installationRoot where the scanner workers live
     * @param string $databasePath where $pdo lives; sites the enrichers file
     */
    public function __construct(
        private PDO $pdo,
        private string $installationRoot,
        string $databasePath,
    ) {
        $this->enrichers = EnrichersFile::forDatabase($databasePath);
    }

    /**
     * A scanner of `$roots` that records each scan of an existing project in the scan ledger.
     *
     * @param AllowedRoots|list<string> $roots
     */
    public function ledgered(AllowedRoots|array $roots): LedgeredScanner
    {
        return LedgeredScanner::local($this->pdo, $this->installationRoot, $roots, enrichers: $this->enrichers);
    }

    /**
     * The scan itself, unrecorded: only for a caller that takes the project's
     * write lease, passes it in the scan options and records the ledger entry
     * itself under that lease.
     *
     * @param AllowedRoots|list<string> $roots
     */
    public function underOwnLease(AllowedRoots|array $roots): ProjectScanService
    {
        return new ProjectScanService($this->pdo, $this->installationRoot, $roots, enrichers: $this->enrichers);
    }
}
