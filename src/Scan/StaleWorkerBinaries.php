<?php

declare(strict_types=1);

namespace Knossos\Scan;

use Knossos\Scanner\Protocol\ScannerManifest;
use Knossos\Scanner\Worker\WorkerSourceHash;

/**
 * The compiled workers a scan ran whose binary was not built from the source
 * beside it.
 *
 * `git pull` updates a worker's source but not its git-ignored binary, so the
 * scan runs older code than the checkout holds and nothing about its result
 * says so: facts the newer source would find are missing, and what follows
 * from them, a dead-code candidate for one, reads as a finding about the
 * project. `doctor` reports the same mismatch, but only to someone who runs
 * it. A scan that used such a binary says so itself.
 *
 * Only a worker with a `Cargo.toml` beside its `src/` is compared: the others
 * run from source. A worker that did not run in this scan, because every file
 * of its language was reused from the cache, is not asked.
 */
final class StaleWorkerBinaries
{
    private function __construct() {}

    /**
     * One warning per stale worker binary among the workers that ran.
     *
     * @param list<object> $manifests what each worker that ran announced
     * @return list<string>
     */
    public static function warnings(string $installationRoot, array $manifests): array
    {
        $warnings = [];
        if ($installationRoot === '') {
            return $warnings;
        }
        foreach ($manifests as $manifest) {
            if (!$manifest instanceof ScannerManifest || preg_match('/^knossos\\.([a-z0-9_-]+)$/D', $manifest->id, $match) !== 1) {
                continue;
            }
            $key = $match[1];
            $root = $installationRoot . '/workers/' . $key;
            $source = is_file($root . '/Cargo.toml') ? WorkerSourceHash::of($root) : null;
            if ($source === null || $source === $manifest->sourceHash) {
                continue;
            }
            $warnings[] = sprintf(
                'WORKER_BINARY_STALE: The %1$s worker binary was not built from the source in workers/%1$s (%2$s), so this scan did not run the checked-out worker code.'
                . ' Rebuild it with tools/install, or `cargo build --release --locked` in workers/%1$s and copy the binary from target/release into workers/%1$s/bin/, then rescan.',
                $key,
                $manifest->sourceHash === null
                    ? 'the binary predates source hashes'
                    : sprintf('binary %s, checkout %s', substr($manifest->sourceHash, 0, 12), substr($source, 0, 12)),
            );
        }

        return $warnings;
    }
}
