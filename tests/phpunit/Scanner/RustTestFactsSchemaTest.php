<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\TestFidelity\Facts\TestCaseFact;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\Support\ScanLimits;
use Knossos\Tests\Phpunit\Support\SchemaSubset;
use Knossos\Tests\Phpunit\Support\WorkerClients;

use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * The Rust worker's `test_case` nodes and their `contains` edges fit the
 * wire schema, over every Rust test-fidelity fixture, and the core's reader
 * keeps each case and every assertion and operand it carries.
 */
#[Group('test-fidelity')]
final class RustTestFactsSchemaTest extends KnossosTestCase
{
    use WorkerClients;

    protected function setUp(): void
    {
        parent::setUp();
        if (!is_file(self::rustWorkerBinary())) {
            self::markTestSkipped('The Rust worker binary is not built.');
        }
    }

    public function testWorkerOutputOnTheFixturesValidates(): void
    {
        $schema = json_decode((string) file_get_contents(self::repositoryRoot() . '/schemas/scanner/v1/contribution.schema.json'), true, 512, JSON_THROW_ON_ERROR);
        $base = self::repositoryRoot() . '/tests/Fixtures/test-fidelity/rust';
        $checked = 0;
        foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $root) {
            foreach ($this->scan($root) as $contribution) {
                $raw = json_decode((string) json_encode([...$contribution->nodes, ...$contribution->edges]), true, 512, JSON_THROW_ON_ERROR);
                foreach ($raw as $fact) {
                    $definition = isset($fact['local_id']) ? 'node' : 'edge';
                    assertSame([], SchemaSubset::errors(['$ref' => '#/$defs/' . $definition], $fact, $schema), basename($root) . ' ' . $contribution->ownerKey);
                }
                foreach ($contribution->nodes as $node) {
                    if ($node->kind !== 'test_case') {
                        continue;
                    }
                    $case = TestCaseFact::fromNode($node);
                    assertNotNull($case, $node->canonicalName);
                    $assertions = is_array($node->attributes['assertions'] ?? null) ? $node->attributes['assertions'] : [];
                    assertSame(count($assertions), count($case->assertions), $node->canonicalName);
                    $operands = array_sum(array_map(static fn(array $assertion): int => count($assertion['operands'] ?? []), $assertions));
                    assertSame($operands, array_sum(array_map(static fn($assertion): int => count($assertion->operands), $case->assertions)), $node->canonicalName);
                    ++$checked;
                }
            }
        }
        assertSame(true, $checked > 20);
    }

    /**
     * Scan every Rust file under a fixture root through the worker.
     *
     * @return list<ScanContribution>
     */
    private function scan(string $root): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'rs') {
                $files[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
        sort($files);
        $client = $this->rustWorkerClient();
        try {
            return iterator_to_array($client->scan(['limits' => ScanLimits::DEFAULT, 'root' => $root, 'files' => $files, 'config_files' => ['Cargo.toml']]), false);
        } finally {
            $client->shutdown();
        }
    }
}
