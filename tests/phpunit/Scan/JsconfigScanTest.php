<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

use function PHPUnit\Framework\assertContains;

/**
 * A JavaScript project declaring its module root in `jsconfig.json`, as a
 * Vue CLI project does: its bare imports resolve from that root, an
 * extensionless `.vue` component included.
 */
final class JsconfigScanTest extends KnossosTestCase
{
    #[Group('scan')]
    public function testImportsFromAJsconfigModuleRootResolve(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('jsconfig');
        try {
            $statement = $pdo->prepare(
                "SELECT t.canonical_name FROM edges e JOIN nodes s ON s.id = e.source_id JOIN nodes t ON t.id = e.target_id "
                . "WHERE e.project_id = ? AND e.kind = 'imports' AND s.canonical_name = 'src/main.js'",
            );
            $statement->execute([$projectId]);
            $targets = $statement->fetchAll(\PDO::FETCH_COLUMN);

            assertContains('src/checkout/components/Search.js', $targets);
            assertContains('src/checkout/components/Card.vue', $targets);
        } finally {
            $this->removeTempTree($root);
        }
    }
}
