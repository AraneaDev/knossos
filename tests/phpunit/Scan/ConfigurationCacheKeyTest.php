<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

use function PHPUnit\Framework\assertSame;

/**
 * The contribution cache is keyed on the knossos.json settings that change
 * what a worker emits: ignores, frameworks and the file limits. A budget, a
 * policy or the snapshot retention is read by queries, so editing one reuses
 * every cached file instead of reparsing the project.
 */
final class ConfigurationCacheKeyTest extends KnossosTestCase
{
    /**
     * @param array<string, mixed> $config
     */
    private static function write(string $root, array $config): void
    {
        file_put_contents($root . '/knossos.json', json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
    }

    #[Group('scan')]
    public function testOnlyASettingAWorkerReadsInvalidatesTheCache(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-config-key-' . bin2hex(random_bytes(6));
        mkdir($root . '/src', 0o700, true);
        file_put_contents($root . '/composer.json', "{\"name\": \"demo/config\", \"autoload\": {\"psr-4\": {\"App\\\\\": \"src/\"}}}\n");
        foreach (['A', 'B', 'C'] as $class) {
            file_put_contents($root . "/src/{$class}.php", "<?php\n\nnamespace App;\n\nfinal class {$class}\n{\n}\n");
        }
        self::write($root, ['version' => 1, 'quality_budgets' => ['new_cycles' => 0]]);
        $pdo = $this->freshTestDatabase();
        try {
            $scanner = new ProjectScanService($pdo, self::repositoryRoot(), [$root]);
            $scanner->scan($root);

            self::write($root, ['version' => 1, 'quality_budgets' => ['new_cycles' => 1], 'snapshot_retention' => 3,
                'policies' => [['id' => 'p', 'from_boundary' => 'src', 'deny_targets' => ['vendor']]]]);
            $queryOnly = $scanner->scan($root)->data;
            assertSame([0, 3], [$queryOnly['changed_files'], $queryOnly['unchanged_files']], 'A query-time setting reparses nothing.');

            self::write($root, ['version' => 1, 'ignores' => ['src/C.php']]);
            $ignores = $scanner->scan($root)->data;
            assertSame(0, $ignores['unchanged_files'], 'An ignore pattern reaches the workers, so every file is scanned again.');
        } finally {
            unset($pdo);
            $this->removeTempTree($root);
        }
    }
}
