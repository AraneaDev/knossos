<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Store\SqliteGraphRepository;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A helper kept beside a package's source roots for its tests to import (a
 * `scripts/` module the guard tests call) is test support. Reported as a
 * `test_only` candidate it read as dead product code, and every one of them
 * had to be annotated away.
 */
final class TestSupportCandidatesTest extends KnossosTestCase
{
    #[Group('query')]
    public function testATestOnlyHelperOutsideTheSourceRootsIsTestSupport(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $suite = $this->testModule($repository, $ids, 'frontend/src/test/contrast.test.ts');
        $script = $this->function($repository, $ids, 'frontend/scripts/tokens.mjs', 'afstand');
        $product = $this->function($repository, $ids, 'frontend/src/lib/colors.ts', 'mix');
        $this->function($repository, $ids, 'frontend/scripts/unused.mjs', 'nobody');
        foreach ([$script, $product] as $target) {
            $repository->saveEdge(StableId::edge($ids['project'], 'calls', $suite, $target, 't:1'), $ids['project'], 'calls', $suite, $target, $ids['file'], 1, 1, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        }
        $repository->completeScan($ids['project'], $ids['scan']);

        $data = ArchitectureQueryService::forDatabase($pdo)->services()->healthQueries->architectureHealth($ids['project'], limit: 100)->data;
        $reachability = [];
        foreach ($data['dead_code_candidates'] as $candidate) {
            $reachability[$candidate['component']['canonical_name']] = $candidate['reachability'];
        }

        self::assertArrayNotHasKey('frontend/scripts/tokens.mjs#afstand', $reachability);
        self::assertSame(1, $data['bounds']['excluded_test_support']);
        // Under a source root, a function only tests call is still product
        // code nothing else uses.
        self::assertSame('test_only', $reachability['frontend/src/lib/colors.ts#mix'] ?? null);
        // Nothing reaches this one at all, tests included: it stays dead.
        self::assertSame('unreferenced', $reachability['frontend/scripts/unused.mjs#nobody'] ?? null);
    }

    /**
     * Two pages each declaring their own `StatusBadge` do not use a shared
     * component of that name: the shared one, which nothing imports, stays
     * a candidate beside the local ones that are used.
     */
    #[Group('query')]
    public function testASharedComponentShadowedByLocalOnesOfItsNameStaysDead(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $this->function($repository, $ids, 'frontend/src/components/common/StatusBadge.tsx', 'StatusBadge');
        foreach (['DataQuality', 'SystemHealth'] as $page) {
            $local = $this->function($repository, $ids, "frontend/src/pages/{$page}.tsx", 'StatusBadge');
            $view = $this->function($repository, $ids, "frontend/src/pages/{$page}.tsx", $page);
            $repository->saveEdge(StableId::edge($ids['project'], 'references', $view, $local, "{$page}:1"), $ids['project'], 'references', $view, $local, $ids['file'], 1, 1, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        }
        $repository->completeScan($ids['project'], $ids['scan']);

        $data = ArchitectureQueryService::forDatabase($pdo)->services()->healthQueries->architectureHealth($ids['project'], limit: 100)->data;
        $names = array_map(static fn(array $candidate): string => $candidate['component']['canonical_name'], $data['dead_code_candidates']);

        self::assertContains('frontend/src/components/common/StatusBadge.tsx#StatusBadge', $names);
        self::assertNotContains('frontend/src/pages/DataQuality.tsx#StatusBadge', $names);
        self::assertNotContains('frontend/src/pages/SystemHealth.tsx#StatusBadge', $names);
    }

    /**
     * A TypeScript function declared in its own file.
     *
     * @param array<string, string> $ids
     */
    private function function(SqliteGraphRepository $repository, array $ids, string $path, string $name): string
    {
        $file = $this->file($repository, $ids, $path);
        $id = StableId::symbol($ids['project'], 'typescript', 'function', "{$path}#{$name}");
        $repository->saveNode($id, $ids['project'], 'typescript', 'function', "{$path}#{$name}", $name, null, $file, 1, 1, 'ast', 'certain', [], "typescript:file:{$path}", $ids['scan']);

        return $id;
    }

    /** @param array<string, string> $ids */
    private function testModule(SqliteGraphRepository $repository, array $ids, string $path): string
    {
        $file = $this->file($repository, $ids, $path);
        $id = StableId::symbol($ids['project'], 'typescript', 'module', $path);
        $repository->saveNode($id, $ids['project'], 'typescript', 'module', $path, basename($path), null, $file, 1, 1, 'ast', 'certain', [], "typescript:file:{$path}", $ids['scan']);
        $repository->saveClassification(StableId::classification($ids['project'], $id, 'quality.test_module', 'core.test.modules.v1'), $ids['project'], $id, 'quality.test_module', 'derived', 'probable', 'core.test.modules.v1', $file, 1, 1, [], $ids['scan']);

        return $id;
    }

    /** @param array<string, string> $ids */
    private function file(SqliteGraphRepository $repository, array $ids, string $path): string
    {
        $file = StableId::file($ids['project'], $path);
        $repository->saveFile($file, $ids['project'], $path, hash('sha256', $path), 100, 1, 'typescript', '0.1.0', $ids['scan']);

        return $file;
    }
}
