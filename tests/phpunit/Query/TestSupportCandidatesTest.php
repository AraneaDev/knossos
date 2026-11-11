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
     * Rust code gated by a test-support feature
     * (`#[cfg(feature = "test-support")]`) is compiled only for the tests,
     * which the scanner marks: it is test support whether or not a test
     * reaches it, and counted as such rather than reported as dead.
     */
    #[Group('query')]
    public function testCodeTheScannerMarksAsTestSupportIsExcludedAndCounted(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $this->function($repository, $ids, 'crates/host/src/fs.rs', 'publication_unknown_fixture_error', ['test_support' => true]);
        $this->function($repository, $ids, 'crates/host/src/fs.rs', 'really_dead');
        $repository->completeScan($ids['project'], $ids['scan']);

        $data = ArchitectureQueryService::forDatabase($pdo)->services()->healthQueries->architectureHealth($ids['project'], limit: 100)->data;
        $names = array_map(static fn(array $candidate): string => $candidate['component']['canonical_name'], $data['dead_code_candidates']);

        self::assertNotContains('crates/host/src/fs.rs#publication_unknown_fixture_error', $names);
        self::assertContains('crates/host/src/fs.rs#really_dead', $names);
        self::assertSame(1, $data['bounds']['excluded_test_support']);
    }

    /**
     * A triage page: candidates under one path only, a test-only candidate
     * saying which test reaches it, and on request the candidates an
     * annotation hid, with the annotation.
     */
    #[Group('query')]
    public function testCandidatesFilterByPathSayWhereTestsReachThemAndShowWhatAnnotationsHid(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $suite = $this->testModule($repository, $ids, 'tests/unit/security.test.ts');
        $tested = $this->function($repository, $ids, 'src/security.ts', 'createAuditLog');
        $this->function($repository, $ids, 'src/security.ts', 'nobody');
        $this->function($repository, $ids, 'src/other.ts', 'elsewhere');
        $hidden = $this->function($repository, $ids, 'src/security.ts', 'loadedByName');
        $repository->saveEdge(StableId::edge($ids['project'], 'calls', $suite, $tested, 't:9'), $ids['project'], 'calls', $suite, $tested, $ids['file'], 9, 9, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        $repository->completeScan($ids['project'], $ids['scan']);
        $queries = ArchitectureQueryService::forDatabase($pdo);
        $queries->services()->annotationQueries->upsertAnnotation($ids['project'], 'src/security.ts#loadedByName', 'false_positive', 'loaded by name', execute: true);
        unset($hidden);
        $health = $queries->services()->healthQueries;
        $byName = static function (array $data): array {
            $out = [];
            foreach ($data['dead_code_candidates'] as $candidate) {
                $out[$candidate['component']['canonical_name']] = $candidate;
            }

            return $out;
        };

        $page = $byName($health->architectureHealth($ids['project'], limit: 100, pathPrefix: 'src/security.ts')->data);
        self::assertSame(['src/security.ts#nobody', 'src/security.ts#createAuditLog'], array_keys($page));
        self::assertSame(['path' => 'src/Checkout.php', 'line' => 9], $page['src/security.ts#createAuditLog']['test_reference']);
        self::assertSame(1, $page['src/security.ts#createAuditLog']['test_reference_count']);
        self::assertArrayNotHasKey('test_reference', $page['src/security.ts#nobody']);

        $all = $byName($health->architectureHealth($ids['project'], limit: 100, pathPrefix: 'src/security.ts', includeAnnotated: true)->data);
        self::assertSame(true, $all['src/security.ts#loadedByName']['hidden_by_annotation'] ?? null);
        self::assertSame('false_positive', $all['src/security.ts#loadedByName']['annotation']['kind'] ?? null);
    }

    /**
     * An implementation is out of the report while the contract it fulfils is
     * reached: called through the interface, it is reached through it. One
     * whose interface member nothing reaches is judged on its own callers:
     * a method only a test calls is test-only, one nothing calls is
     * unreferenced, beside the interface member.
     */
    #[Group('query')]
    public function testAnImplementationIsExcludedOnlyWhileItsContractIsReached(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $node = function (string $kind, string $name, array $attributes = []) use ($repository, $ids): string {
            $id = StableId::symbol($ids['project'], 'typescript', $kind, $name);
            $repository->saveNode($id, $ids['project'], 'typescript', $kind, $name, (string) preg_replace('/^.*(::|#)/', '', $name), null, $ids['file'], 1, 1, 'ast', 'certain', $attributes, 'php:file:src/Checkout.php', $ids['scan']);

            return $id;
        };
        $edge = function (string $kind, string $source, string $target, int $line) use ($repository, $ids): void {
            $repository->saveEdge(StableId::edge($ids['project'], $kind, $source, $target, "e{$line}"), $ids['project'], $kind, $source, $target, $ids['file'], $line, $line, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        };
        $interface = $node('interface', 'src/types.ts#ISecurity');
        $class = $node('class', 'src/sec.ts#Security');
        $edge('implements', $class, $interface, 1);
        $members = [];
        foreach (['viaInterface', 'auditLog', 'sanitize'] as $index => $method) {
            $declared = $node('method', "src/types.ts#ISecurity::{$method}");
            $edge('contains', $interface, $declared, 10 + $index);
            $implemented = $node('method', "src/sec.ts#Security::{$method}", ['overrides' => true]);
            $edge('contains', $class, $implemented, 20 + $index);
            $members[$method] = [$declared, $implemented];
        }
        $product = $node('function', 'src/main.ts#main');
        $suite = $this->testModule($repository, $ids, 'tests/security.test.ts');
        $edge('calls', $product, $members['viaInterface'][0], 30);
        $edge('calls', $suite, $members['auditLog'][1], 31);
        $edge('calls', $product, $class, 32);
        $repository->completeScan($ids['project'], $ids['scan']);

        $data = ArchitectureQueryService::forDatabase($pdo)->services()->healthQueries->architectureHealth($ids['project'], limit: 100)->data;
        $reachability = [];
        foreach ($data['dead_code_candidates'] as $candidate) {
            $reachability[$candidate['component']['canonical_name']] = $candidate['reachability'];
        }

        self::assertArrayNotHasKey('src/sec.ts#Security::viaInterface', $reachability);
        self::assertSame('test_only', $reachability['src/sec.ts#Security::auditLog'] ?? null);
        self::assertSame('unreferenced', $reachability['src/sec.ts#Security::sanitize'] ?? null);
        self::assertSame('unreferenced', $reachability['src/types.ts#ISecurity::sanitize'] ?? null);
    }

    /**
     * Example code is never imported by design: left out of the candidates
     * and counted, unless asked for. What only an example uses is reached the
     * way what only a test uses is: test-only.
     */
    #[Group('query')]
    public function testExampleCodeIsLeftOutAndItsUseCountsAsATestsDoes(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $template = $this->function($repository, $ids, 'examples/custom-adapters/adapter-template.ts', 'TemplateAdapter');
        $repository->saveClassification(StableId::classification($ids['project'], $template, 'quality.example_module', 'core.example.modules.v1'), $ids['project'], $template, 'quality.example_module', 'derived', 'probable', 'core.example.modules.v1', $ids['file'], 1, 1, [], $ids['scan']);
        $shown = $this->function($repository, $ids, 'src/adapters.ts', 'registerAdapter');
        $repository->saveEdge(StableId::edge($ids['project'], 'calls', $template, $shown, 'x:1'), $ids['project'], 'calls', $template, $shown, $ids['file'], 1, 1, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        $repository->completeScan($ids['project'], $ids['scan']);
        $health = ArchitectureQueryService::forDatabase($pdo)->services()->healthQueries;
        $reachability = static function (array $data): array {
            $out = [];
            foreach ($data['dead_code_candidates'] as $candidate) {
                $out[$candidate['component']['canonical_name']] = $candidate['reachability'];
            }

            return $out;
        };

        $default = $health->architectureHealth($ids['project'], limit: 100)->data;
        self::assertArrayNotHasKey('examples/custom-adapters/adapter-template.ts#TemplateAdapter', $reachability($default));
        self::assertSame(1, $default['bounds']['excluded_example_components']);
        self::assertSame('test_only', $reachability($default)['src/adapters.ts#registerAdapter'] ?? null);

        $all = $health->architectureHealth($ids['project'], limit: 100, includeExamples: true)->data;
        self::assertSame('unreferenced', $reachability($all)['examples/custom-adapters/adapter-template.ts#TemplateAdapter'] ?? null);
        self::assertSame(0, $all['bounds']['excluded_example_components']);
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
     * @param array<string, mixed> $attributes
     */
    private function function(SqliteGraphRepository $repository, array $ids, string $path, string $name, array $attributes = []): string
    {
        $file = $this->file($repository, $ids, $path);
        $id = StableId::symbol($ids['project'], 'typescript', 'function', "{$path}#{$name}");
        $repository->saveNode($id, $ids['project'], 'typescript', 'function', "{$path}#{$name}", $name, null, $file, 1, 1, 'ast', 'certain', $attributes, "typescript:file:{$path}", $ids['scan']);

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
