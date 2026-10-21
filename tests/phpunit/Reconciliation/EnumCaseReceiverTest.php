<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Reconciliation;

use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A call on the case `Enum::from()` or `Enum::tryFrom()` hands back names the
 * enum's method. PHP declares both on every backed enum itself, so no
 * declaration in the graph returned anything and the call was dropped, leaving
 * the method looking unreferenced. A class's own `from()` is not an enum's.
 */
#[Group('reconciliation')]
final class EnumCaseReceiverTest extends KnossosTestCase
{
    public function testACallOnTheCaseFromOrTryFromReturnsNamesTheEnumsMethod(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-enum-case-' . bin2hex(random_bytes(6));
        mkdir($root . '/src', 0o777, true);
        file_put_contents($root . '/src/Kind.php', "<?php\nnamespace App;\n\nenum Kind: string\n{\n    case A = 'a';\n\n    public function label(): string\n    {\n        return 'A';\n    }\n\n    public function isA(): bool\n    {\n        return \$this === self::A;\n    }\n}\n");
        file_put_contents($root . '/src/Plain.php', "<?php\nnamespace App;\n\nfinal class Plain\n{\n    public static function from(string \$value): string\n    {\n        return \$value;\n    }\n\n    public function unused(): void\n    {\n    }\n}\n");
        file_put_contents($root . '/src/Reader.php', "<?php\nnamespace App;\n\nfinal class Reader\n{\n    public function read(string \$raw): string\n    {\n        Plain::from(\$raw)->unused();\n\n        return Kind::tryFrom(\$raw)?->label() . (Kind::from(\$raw)->isA() ? 'y' : 'n');\n    }\n}\n");
        try {
            $pdo = $this->freshTestDatabase();
            (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);
            $calls = $pdo->query(
                "SELECT t.canonical_name FROM edges e JOIN nodes s ON s.id = e.source_id JOIN nodes t ON t.id = e.target_id
                 WHERE e.kind = 'calls' AND s.canonical_name = 'App\\Reader::read' ORDER BY t.canonical_name",
            )->fetchAll(\PDO::FETCH_COLUMN);
        } finally {
            $this->removeTempTree($root);
        }

        self::assertSame(['App\\Kind::from', 'App\\Kind::isA', 'App\\Kind::label', 'App\\Kind::tryFrom', 'App\\Plain::from'], $calls);
    }
}
