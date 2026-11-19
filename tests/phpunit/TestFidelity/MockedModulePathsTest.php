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
