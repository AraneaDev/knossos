<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity;

use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Tests\Phpunit\Support\ScanLimits;

/**
 * Runs the real TypeScript worker over a few files written to a scratch
 * project, for the test-fidelity end-to-end tests.
 */
trait ScansTypeScript
{
    /**
     * The worker's contributions for a project of `$files` (path => contents);
     * its `.ts` files are scanned and `tsconfig.json` is read as config.
     *
     * @param array<string, string> $files
     * @return list<ScanContribution>
     */
    private function scanTypeScript(array $files): array
    {
        $root = sys_get_temp_dir() . '/knossos-fidelity-e2e-' . bin2hex(random_bytes(6));
        foreach ($files as $path => $contents) {
            if (!is_dir(dirname($root . '/' . $path))) {
                mkdir(dirname($root . '/' . $path), 0o777, true);
            }
            file_put_contents($root . '/' . $path, $contents);
        }
        $client = $this->typescriptWorkerClient();
        try {
            /** @var list<ScanContribution> $contributions */
            $contributions = [...$client->scan([
                'limits' => ScanLimits::DEFAULT,
                'root' => $root,
                'files' => array_values(array_filter(array_keys($files), static fn(string $path): bool => str_ends_with($path, '.ts'))),
                'config_files' => ['tsconfig.json'],
            ])];
        } finally {
            $client->shutdown();
            self::removeTree($root);
        }

        return $contributions;
    }

    private static function removeTree(string $path): void
    {
        foreach (is_dir($path) ? array_diff(scandir($path) ?: [], ['.', '..']) : [] as $entry) {
            is_dir($path . '/' . $entry) ? self::removeTree($path . '/' . $entry) : unlink($path . '/' . $entry);
        }
        is_dir($path) ? rmdir($path) : null;
    }
}
