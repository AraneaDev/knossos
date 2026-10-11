<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\AbstractArchitectureQueryService;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\TestReachSearch;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertSame;

/**
 * A call through an interface reaches every implementation: `Uses::run`
 * calls `Reads::read`, which `Fancy::read` fulfils, and `Fancy::read` is the
 * only user of `Helper`.
 */
final class DispatchReachTest extends KnossosTestCase
{
    /** @return array{0: PDO, 1: string, 2: string} */
    private function dispatching(): array
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('turn-brief');
        $php = static fn(string $body): string => "<?php\n\ndeclare(strict_types=1);\n\nnamespace App;\n\n{$body}\n";
        file_put_contents($root . '/src/Core/Reads.php', $php("interface Reads\n{\n    public function read(): string;\n}"));
        file_put_contents($root . '/src/Core/Plain.php', $php("final class Plain implements Reads\n{\n    public function read(): string\n    {\n        return 'plain';\n    }\n}"));
        file_put_contents($root . '/src/Core/Fancy.php', $php("final class Fancy implements Reads\n{\n    public function read(): string\n    {\n        return Helper::shine('fancy');\n    }\n}"));
        file_put_contents($root . '/src/Core/Helper.php', $php("final class Helper\n{\n    public static function shine(string \$text): string\n    {\n        return '*' . \$text . '*';\n    }\n}"));
        file_put_contents($root . '/src/Core/Uses.php', $php("final class Uses\n{\n    public function run(Reads \$reads): string\n    {\n        return \$reads->read();\n    }\n}"));
        file_put_contents($root . '/tests/UsesTest.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Tests;\n\nuse App\\Plain;\nuse App\\Uses;\nuse PHPUnit\\Framework\\TestCase;\n\nfinal class UsesTest extends TestCase\n{\n    public function testRuns(): void\n    {\n        self::assertSame('plain', (new Uses())->run(new Plain()));\n    }\n}\n");
        (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);

        return [$pdo, $projectId, $root];
    }

    private static function id(PDO $pdo, string $projectId, string $name): string
    {
        $statement = $pdo->prepare('SELECT id FROM nodes WHERE project_id = ? AND canonical_name = ?');
        $statement->execute([$projectId, $name]);
        return (string) $statement->fetchColumn();
    }

    #[Group('query')]
    public function testATestCallingThroughTheInterfaceReachesWhatAnImplementationUses(): void
    {
        [$pdo, $projectId, $root] = $this->dispatching();
        try {
            $found = ArchitectureQueryService::forDatabase($pdo)->testImpact($projectId, files: ['src/Core/Helper.php']);
            assertContains('tests/UsesTest.php', array_column($found->data['test_files'], 'path'));
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testTheSupertypeReadsCountAgainstTheWalksEdgeBound(): void
    {
        [$pdo, $projectId, $root] = $this->dispatching();
        try {
            $search = (new TestReachSearch($pdo, null, 10_000, 1))->search($projectId, [self::id($pdo, $projectId, 'App\\Fancy::read')], 4, AbstractArchitectureQueryService::IMPACT_EDGE_KINDS, 1, PHP_INT_MAX);
            assertSame(['edge_limit'], $search['truncation_reasons']);
        } finally {
            $this->removeTempTree($root);
        }
    }
}
