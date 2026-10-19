<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertArrayNotHasKey;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * Rust names a method after its type, so a method an impl block in another
 * file declares reads as if it lived beside the type. Results name the file
 * it is declared in when that is not its type's.
 */
final class MemberDeclaredInTest extends KnossosTestCase
{
    #[Group('query')]
    public function testAMethodDeclaredApartFromItsTypeNamesItsFile(): void
    {
        if (!is_file(self::rustWorkerBinary())) {
            self::markTestSkipped('The Rust worker binary is not built.');
        }
        $root = sys_get_temp_dir() . '/knossos-stale-declared-in-' . bin2hex(random_bytes(6));
        mkdir($root . '/src', 0o700, true);
        file_put_contents($root . '/Cargo.toml', "[package]\nname = \"apart\"\nversion = \"0.1.0\"\n");
        file_put_contents($root . '/src/lib.rs', "mod state;\nmod routes;\n");
        file_put_contents($root . '/src/state.rs', "pub struct Calls;\n\nimpl Calls {\n    pub fn near(&self) {}\n}\n");
        file_put_contents($root . '/src/routes.rs', "use crate::state::Calls;\n\nimpl Calls {\n    pub fn far(&self) {}\n}\n");
        $pdo = $this->freshTestDatabase();
        try {
            (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);
            $projectId = (string) $pdo->query('SELECT id FROM projects')->fetchColumn();
            $queries = ArchitectureQueryService::forDatabase($pdo);

            $far = $queries->findComponent($projectId, 'crate::state::Calls::far')->data['components'][0];
            assertSame('src/routes.rs', $far['declared_in'] ?? null);
            $near = $queries->findComponent($projectId, 'crate::state::Calls::near')->data['components'][0];
            assertArrayNotHasKey('declared_in', $near);

            assertSame('src/routes.rs', $queries->inspectComponent($projectId, 'crate::state::Calls::far')->data['component']['declared_in'] ?? null);
            $searched = $queries->searchArchitecture($projectId, 'far')->data['results'];
            assertSame('src/routes.rs', $searched[0]['declared_in'] ?? null);
        } finally {
            unset($pdo);
            $this->removeTempTree($root);
        }
    }
}
