<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Configuration;

use Knossos\Configuration\ProjectConfigurationLoader;
use Knossos\Discovery\DiscoveryException;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

use function PHPUnit\Framework\assertSame;

/**
 * The loader's `test_finding_suppressions`. Beside ProjectConfigurationLoaderTest
 * rather than in it, which is at the file-length ratchet.
 */
#[Group('project-configuration-loader')]
final class TestFindingSuppressionsConfigurationTest extends KnossosTestCase
{
    public function testLoaderReadsTestFindingSuppressions(): void
    {
        $root = $this->rootWith([
            ['rule' => 'GHOST_MEMBER', 'reason' => 'legacy mocks'],
            ['rule' => 'TAUTOLOGY/mock_echo', 'path' => 'tests/legacy/'],
        ]);
        try {
            assertSame([
                ['rule' => 'GHOST_MEMBER', 'path' => null, 'reason' => 'legacy mocks'],
                ['rule' => 'TAUTOLOGY/mock_echo', 'path' => 'tests/legacy/', 'reason' => null],
            ], ProjectConfigurationLoader::load($root, [$root])->testFindingSuppressions);
        } finally {
            $this->removeRoot($root);
        }
    }

    public function testLoaderRejectsMalformedEntries(): void
    {
        foreach ([
            [[['rule' => 'X', 'line' => 3]], 'PROJECT_CONFIG_INVALID: test_finding_suppressions entries take rule, path and reason only.'],
            [[['rule' => '']], 'PROJECT_CONFIG_INVALID: test_finding_suppressions entries need a rule.'],
            [[['path' => 'a/']], 'PROJECT_CONFIG_INVALID: test_finding_suppressions entries need a rule.'],
            [[['rule' => 'X', 'path' => '']], 'PROJECT_CONFIG_INVALID: a test_finding_suppressions path or reason must be a non-empty string.'],
            [array_fill(0, 201, ['rule' => 'X']), null],
        ] as [$entries, $message]) {
            $root = $this->rootWith($entries);
            try {
                ProjectConfigurationLoader::load($root, [$root]);
                self::fail('a malformed test_finding_suppressions should be refused');
            } catch (DiscoveryException $e) {
                if ($message !== null) {
                    assertSame($message, $e->getMessage());
                }
            } finally {
                $this->removeRoot($root);
            }
        }
    }

    /** @param list<array<string, mixed>> $entries */
    private function rootWith(array $entries): string
    {
        $root = self::scratchPath('knossos-config');
        mkdir($root, 0755, true);
        file_put_contents($root . '/knossos.json', json_encode(['version' => 1, 'test_finding_suppressions' => $entries]));

        return $root;
    }

    private function removeRoot(string $root): void
    {
        @unlink($root . '/knossos.json');
        @rmdir($root);
    }
}
