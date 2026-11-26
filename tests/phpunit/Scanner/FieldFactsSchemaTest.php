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
 * The TypeScript and PHP workers' `fields` and `cases` fit the wire schema
 * over the fixture-drift tree, and the core reads each list they send.
 */
final class FieldFactsSchemaTest extends KnossosTestCase
{
    public function testWorkerOutputOnTheFixturesValidates(): void
    {
        $schema = json_decode((string) file_get_contents(self::repositoryRoot() . '/schemas/scanner/v1/contribution.schema.json'), true, 512, JSON_THROW_ON_ERROR);
        $lists = [];
        foreach (glob(self::repositoryRoot() . '/tests/Fixtures/test-fidelity/fixtures/*', GLOB_ONLYDIR) ?: [] as $root) {
            foreach ($this->scan($root) as $contribution) {
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

        assertSame([
            'Bag' => ['id'],
            'BagTest' => [],
            'ConsignmentRecord' => ['destination', 'reference', 'stops', 'weightKg'],
            'Lane' => ['Fast' => 'fast', 'Slow' => 'slow'],
            'LaneRecord' => ['id', 'lane'],
            'ShipmentRecord' => ['express', 'id', 'weight'],
            'ShippingAddress' => ['city', 'street'],
            'UserRecord' => ['email', 'id', 'name'],
        ], $lists);
    }

    /**
     * Scan every TypeScript and PHP source under a fixture root through its worker.
     *
     * @return list<ScanContribution>
     */
    private function scan(string $root): array
    {
        $byLanguage = ['ts' => [], 'php' => []];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && isset($byLanguage[$file->getExtension()])) {
                $byLanguage[$file->getExtension()][] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
        $contributions = [];
        foreach ($byLanguage as $language => $files) {
            if ($files === []) {
                continue;
            }
            sort($files);
            $client = $language === 'ts' ? $this->typescriptWorkerClient() : $this->phpWorkerClient();
            $contributions = [...$contributions, ...$this->scanWith($client, $root, $files)];
        }

        return $contributions;
    }

    /**
     * @param list<string> $files
     * @return list<ScanContribution>
     */
    private function scanWith(ProcessScannerClient $client, string $root, array $files): array
    {
        try {
            return iterator_to_array($client->scan(['limits' => ScanLimits::DEFAULT, 'root' => $root, 'files' => $files]), false);
        } finally {
            $client->shutdown();
        }
    }
}
