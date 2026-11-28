<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\TestFidelity\Facts\StubbedMember;
use Knossos\TestFidelity\Facts\TestDouble;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\Support\ScanLimits;
use Knossos\Tests\Phpunit\Support\SchemaSubset;

use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * The Python worker's `test_double` nodes and their `contains` and `doubles`
 * edges fit the wire schema, over every Python test-fidelity fixture, and the
 * core's reader keeps each double and every member and matcher it carries.
 */
#[Group('python-scanner')]
#[Group('test-fidelity')]
final class PythonTestFactsSchemaTest extends KnossosTestCase
{
    #[Group('slow')]
    public function testWorkerOutputOnTheFixturesValidates(): void
    {
        $schema = json_decode((string) file_get_contents(self::repositoryRoot() . '/schemas/scanner/v1/contribution.schema.json'), true, 512, JSON_THROW_ON_ERROR);
        $base = self::repositoryRoot() . '/tests/Fixtures/test-fidelity/python';
        $roots = ['healthy-idioms', 'momus-drift', 'momus-src-layout', 'nemesis'];
        foreach (glob($base . '/nemesis-experiments/*', GLOB_ONLYDIR) ?: [] as $experiment) {
            $roots[] = 'nemesis-experiments/' . basename($experiment);
        }
        $checked = 0;
        foreach ($roots as $root) {
            foreach ($this->scan($base . '/' . $root) as $contribution) {
                $raw = json_decode((string) json_encode([...$contribution->nodes, ...$contribution->edges]), true, 512, JSON_THROW_ON_ERROR);
                foreach ($raw as $fact) {
                    $definition = isset($fact['local_id']) ? 'node' : 'edge';
                    assertSame([], SchemaSubset::errors(['$ref' => '#/$defs/' . $definition], $fact, $schema), $root . ' ' . $contribution->ownerKey);
                }
                foreach ($contribution->nodes as $node) {
                    if ($node->kind !== 'test_double') {
                        continue;
                    }
                    $double = TestDouble::fromNode($node);
                    assertNotNull($double, $node->canonicalName);
                    $members = is_array($node->attributes['members'] ?? null) ? $node->attributes['members'] : [];
                    assertSame(array_column($members, 'name'), array_map(static fn(StubbedMember $member): string => $member->name, $double->members), $node->canonicalName);
                    $matchers = array_sum(array_map(static fn(array $member): int => count($member['called_with'] ?? []), $members));
                    assertSame($matchers, array_sum(array_map(static fn(StubbedMember $member): int => count($member->calledWith), $double->members)), $node->canonicalName);
                    ++$checked;
                }
            }
        }
        assertSame(true, $checked > 40);
    }

    /**
     * Scan every Python file under a fixture root through the worker.
     *
     * @return list<ScanContribution>
     */
    private function scan(string $root): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'py') {
                $files[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
        sort($files);
        $client = $this->pythonWorkerClient();
        try {
            return iterator_to_array($client->scan(['limits' => ScanLimits::DEFAULT, 'root' => $root, 'files' => $files]), false);
        } finally {
            $client->shutdown();
        }
    }
}
