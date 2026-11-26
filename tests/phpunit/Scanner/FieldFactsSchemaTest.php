<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Scanner\Worker\ProcessScannerClient;
use Knossos\TestFidelity\Facts\EnumCases;
use Knossos\TestFidelity\Facts\FieldList;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\Support\ScanLimits;
use Knossos\Tests\Phpunit\Support\SchemaSubset;

use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertSame;

/**
 * Each worker's `fields` and `cases` fit the wire schema over the
 * fixture-drift tree, and the core reads each list it sends; an open list
 * reads as none.
 */
final class FieldFactsSchemaTest extends KnossosTestCase
{
    public function testWorkerOutputOnTheFixturesValidates(): void
    {
        $lists = $this->lists(['ts', 'php', 'py']);

        assertSame([
            'Bag' => ['id'],
            'BagTest' => [],
            'ConsignmentRecord' => ['destination', 'reference', 'stops', 'weightKg'],
            'Courier' => ['POST' => 'post', 'VAN' => 'van'],
            'Labelled' => [],
            'Lane' => ['Fast' => 'fast', 'Slow' => 'slow'],
            'LaneRecord' => ['id', 'lane'],
            'Manifest' => ['courier', 'reference'],
            'Parcel' => ['courier', 'id', 'label', 'weight_kg'],
            'ShipmentRecord' => ['express', 'id', 'weight'],
            'ShippingAddress' => ['city', 'street'],
            'Stop' => ['city', 'notes'],
            'UserRecord' => ['email', 'id', 'name'],
        ], $lists);
    }

    public function testTheRustWorkerOutputOnTheFixturesValidates(): void
    {
        if (!is_executable(self::rustWorkerBinary())) {
            self::markTestSkipped('The Rust worker is not built.');
        }

        assertSame([
            'Dock' => ['North' => 'North', 'South' => 'South'],
            'Pallet' => ['dock', 'id', 'layers', 'note'],
            'Tracking' => [],
        ], $this->lists(['rs']));
    }

    /**
     * Scan the fixture tree with the workers of `$extensions`, check every fact
     * against the schema, and read each list sent, by the node's display name.
     *
     * @param list<string> $extensions
     * @return array<string, mixed>
     */
    private function lists(array $extensions): array
    {
        $schema = json_decode((string) file_get_contents(self::repositoryRoot() . '/schemas/scanner/v1/contribution.schema.json'), true, 512, JSON_THROW_ON_ERROR);
        $lists = [];
        foreach (glob(self::repositoryRoot() . '/tests/Fixtures/test-fidelity/fixtures/*', GLOB_ONLYDIR) ?: [] as $root) {
            foreach ($this->scan($root, $extensions) as $contribution) {
                $raw = json_decode((string) json_encode([...$contribution->nodes, ...$contribution->edges]), true, 512, JSON_THROW_ON_ERROR);
                foreach ($raw as $fact) {
                    $definition = isset($fact['local_id']) ? 'node' : 'edge';
                    assertSame([], SchemaSubset::errors(['$ref' => '#/$defs/' . $definition], $fact, $schema), basename($root) . ' ' . $contribution->ownerKey);
                }
                foreach ($contribution->nodes as $node) {
                    if (isset($node->attributes['fields'])) {
                        $lists[$node->displayName] = array_keys(FieldList::ofNode($node)?->fields() ?? []);
                    }
                    if (isset($node->attributes['cases'])) {
                        $cases = EnumCases::ofNode($node);
                        assertNotNull($cases, $node->canonicalName);
                        $lists[$node->displayName] = $cases->cases();
                    }
                }
            }
        }
        ksort($lists);

        return $lists;
    }

    /**
     * Scan every source of `$extensions` under a fixture root through its worker.
     *
     * @param list<string> $extensions
     * @return list<ScanContribution>
     */
    private function scan(string $root, array $extensions): array
    {
        $byLanguage = array_fill_keys([...$extensions, 'toml'], []);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && isset($byLanguage[$file->getExtension()])) {
                $byLanguage[$file->getExtension()][] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
        $contributions = [];
        $manifests = array_values(array_filter($byLanguage['toml'], static fn(string $file): bool => basename($file) === 'Cargo.toml'));
        unset($byLanguage['toml']);
        foreach ($byLanguage as $language => $files) {
            if ($files === []) {
                continue;
            }
            sort($files);
            $client = match ($language) {
                'ts' => $this->typescriptWorkerClient(),
                'php' => $this->phpWorkerClient(),
                'py' => $this->pythonWorkerClient(),
                default => $this->rustWorkerClient(),
            };
            $contributions = [...$contributions, ...$this->scanWith($client, $root, $files, $language === 'rs' ? $manifests : [])];
        }

        return $contributions;
    }

    /**
     * @param list<string> $files
     * @param list<string> $manifests the `Cargo.toml` files a Rust scan places its crates by
     * @return list<ScanContribution>
     */
    private function scanWith(ProcessScannerClient $client, string $root, array $files, array $manifests): array
    {
        try {
            return iterator_to_array($client->scan(['limits' => ScanLimits::DEFAULT, 'root' => $root, 'files' => $files, ...($manifests === [] ? [] : ['config_files' => $manifests])]), false);
        } finally {
            $client->shutdown();
        }
    }
}
