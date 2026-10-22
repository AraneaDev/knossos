<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A node traverser calls the hooks of the visitors it is given by name, and
 * the traverser is vendor code. A hook on a visitor subclass is live without
 * an override attribute; another method of the same class is not.
 */
#[Group('query')]
final class DeadCodeVisitorHooksTest extends KnossosTestCase
{
    public function testAVisitorWithoutOverrideIsNotADeadCodeCandidate(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-visitor-hooks-' . bin2hex(random_bytes(6));
        mkdir($root . '/app', 0o777, true);
        file_put_contents($root . '/app/NameCollector.php', "<?php\nnamespace App;\nfinal class NameCollector extends \\PhpParser\\NodeVisitorAbstract\n{\n    public function enterNode(\\PhpParser\\Node \$n)\n    {\n        return null;\n    }\n\n    public function helper(): void {}\n}\n");
        file_put_contents($root . '/app/Walk.php', "<?php\nnamespace App;\nfinal class Walk\n{\n    public function run(): NameCollector\n    {\n        return new NameCollector();\n    }\n}\n");

        try {
            $pdo = $this->freshTestDatabase();
            $projectId = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root)->projectId;
            $data = ArchitectureQueryService::forDatabase($pdo)->services()->healthQueries->architectureHealth($projectId, limit: 100)->data;
        } finally {
            $this->removeTempTree($root);
        }

        $reported = array_map(
            static fn(array $candidate): string => (string) $candidate['component']['display_name'],
            $data['dead_code_candidates'],
        );
        self::assertContains('helper', $reported);
        self::assertNotContains('enterNode', $reported);
    }
}
