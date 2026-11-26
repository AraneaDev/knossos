<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query\TestFidelity;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\TestFidelity\OnRequestFindings;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * What the on-request rules read, loaded from a graph a real worker scanned,
 * and `OVER_MOCKING` over it against Momus's goldens (`test/golden/audit.test.ts`):
 * MOCK-001 at line 1 of `test_patch_imports.py` and `test_patch_missing.py`,
 * and of Rust's `tests/mock_echo.rs` (preflight F1 puts it in place of
 * `gateway_mock.rs`, which holds no double Knossos can see).
 */
#[Group('test-fidelity')]
final class OnRequestFindingsTest extends KnossosTestCase
{
    #[Group('python-scanner')]
    public function testPythonStringPatchesAndAFunctionLocalImport(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('test-fidelity/python/momus-drift');
        try {
            $input = (new OnRequestFindings($pdo))->load($projectId);
            // The worker emits the function-local `from datetime import timedelta` as the
            // module's import; it is local, so it is no dependency (Momus reads module level only).
            self::assertContains(['module' => 'external:datetime', 'name' => 'datetime', 'local' => true], $input->imports['test_patch_imports.py']);
            self::assertContains(['module' => 'external:unittest.mock', 'name' => 'unittest.mock', 'local' => false], $input->imports['test_patch_imports.py']);
            // `patch("prod_imports.idna")` doubles the module prod_imports, which the file never imports.
            self::assertSame(['module' => 'prod_imports.py', 'name' => 'prod_imports'], $input->doubles['test_patch_imports.py'][0]);
            self::assertSame('python', $input->testFiles['test_patch_imports.py']);
            self::assertFalse($input->partial);

            $items = ArchitectureQueryService::forDatabase($pdo)->services()->testFidelityQueries->auditTests($projectId, tier: 'on_request')->data['items'];
            $found = array_map(static fn(array $item): array => [$item['file'], $item['line'], $item['message']], $items);
            self::assertContains(['test_patch_imports.py', 1, '1/1 dependencies mocked, 0 production assertions: over-mocked'], $found);
            self::assertContains(['test_patch_missing.py', 1, '1/1 dependencies mocked, 0 production assertions: over-mocked'], $found);
        } finally {
            $this->removeTempTree($root);
        }
    }

    public function testRustMockEchoIsOverMocked(): void
    {
        if (!is_file(self::rustWorkerBinary())) {
            self::markTestSkipped('The Rust worker binary is not built.');
        }
        [$pdo, $projectId, $root] = $this->scanTempFixture('test-fidelity/rust/tautology');
        try {
            $input = (new OnRequestFindings($pdo))->load($projectId);
            // `use tautology::clock::{elapsed, Clock, MockClock}`: three items of one module, one import.
            self::assertSame([['module' => 'src/clock.rs', 'name' => 'crate::clock', 'local' => false]], $input->imports['tests/mock_echo.rs']);
            self::assertSame(['module' => 'src/clock.rs', 'name' => 'Clock'], $input->doubles['tests/mock_echo.rs'][0]);

            $items = ArchitectureQueryService::forDatabase($pdo)->services()->testFidelityQueries->auditTests($projectId, 'tests/mock_echo.rs', tier: 'on_request')->data['items'];
            self::assertSame([['tests/mock_echo.rs', 1, '1/1 dependencies mocked, 1 production assertions: over-mocked']], array_map(static fn(array $item): array => [$item['file'], $item['line'], $item['message']], $items));
        } finally {
            $this->removeTempTree($root);
        }
    }

    public function testAProjectWithNoTestsLoadsNothing(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('nest');
        try {
            $input = (new OnRequestFindings($pdo))->load($projectId);

            self::assertSame([[], [], [], [], false], [$input->testFiles, $input->imports, $input->doubles, $input->assertions, $input->partial]);
        } finally {
            $this->removeTempTree($root);
        }
    }
}
