<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity;

use Knossos\TestFidelity\MockedModulePaths;
use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * The project files a double's raw module specifier can name, read against
 * the test file's folder with the suffixes the TypeScript worker tries.
 */
#[Group('test-fidelity')]
final class MockedModulePathsTest extends KnossosTestCase
{
    public function testAnExtensionlessSpecifierNamesEachSuffixTheWorkerTries(): void
    {
        $paths = MockedModulePaths::of('tests/UserService.spec.ts', '../src/UserService');

        assertSame('src/UserService', $paths[0]);
        foreach (['src/UserService.ts', 'src/UserService.tsx', 'src/UserService.js', 'src/UserService/index.ts', 'src/UserService/index.js', 'src/UserService.d.ts'] as $path) {
            assertContains($path, $paths);
        }
        assertSame(count($paths), count(array_unique($paths)));
    }

    public function testAJavaScriptExtensionNamesTheTypeScriptSourcesFirst(): void
    {
        assertSame(['src/UserService.ts', 'src/UserService.tsx', 'src/UserService.js'], MockedModulePaths::of('tests/UserService.spec.ts', '../src/UserService.js'));
        assertSame(['lib/a.mts', 'lib/a.mjs'], MockedModulePaths::of('lib/a.test.ts', './a.mjs'));
    }

    /** The worker resolves a mock target with its own table; the two must name the same sources. */
    public function testItNamesTheSourcesTheWorkerResolvesAJavaScriptExtensionTo(): void
    {
        $worker = (string) file_get_contents(self::repositoryRoot() . '/workers/typescript/src/syntax-predicates.js');
        assertSame(1, preg_match('/export const TYPESCRIPT_SOURCES = \{(.*?)\};/s', $worker, $table));
        preg_match_all('/"(\.\w+)":\s*\[([^\]]*)\]/', $table[1], $rows, PREG_SET_ORDER);

        foreach ($rows as $row) {
            preg_match_all('/"(\.\w+)"/', $row[2], $sources);
            $expected = [...array_map(static fn(string $source): string => 'src/a' . $source, $sources[1]), 'src/a' . $row[1]];
            assertSame($expected, MockedModulePaths::of('tests/a.test.ts', '../src/a' . $row[1]), $row[1]);
        }
        assertSame(4, count($rows));
    }

    public function testASpecifierFromTheProjectRootAndADotSegmentAreFolded(): void
    {
        assertSame('a/b', MockedModulePaths::of('spec.ts', './a/./b')[0]);
        assertSame('src/x', MockedModulePaths::of('tests/unit/x.test.ts', '../../src/x')[0]);
    }

    public function testWhatNamesNoProjectFileGivesNone(): void
    {
        foreach (['lodash', '@scope/pkg', 'node:fs', '@/services/x', '~/x', '#internal', '/abs/x', '../../outside', './w.ts?worker', './x#frag', ''] as $specifier) {
            assertSame([], MockedModulePaths::of('tests/x.test.ts', $specifier), $specifier);
        }
    }
}
