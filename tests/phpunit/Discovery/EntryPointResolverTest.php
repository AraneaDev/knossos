<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Discovery;

use Knossos\Discovery\DiscoveredFile;
use Knossos\Discovery\DiscoveryConfig;
use Knossos\Discovery\DiscoveryDiagnostic;
use Knossos\Discovery\EntryPointResolver;
use Knossos\Discovery\ProjectDiscoverer;
use Knossos\Discovery\ProjectUnit;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** The cross-unit entry-point passes, asked with units built by hand. */
#[Group('discovery')]
final class EntryPointResolverTest extends TestCase
{
    /**
     * A declaration file in an `outDir` stands for the source it describes:
     * `.d.ts` for `.js` sources, `.d.mts` for `.mjs`, `.d.cts` for `.cjs`,
     * under the declared `rootDir` and at the same relative path.
     */
    public function testADeclarationInTheOutDirNamesItsSources(): void
    {
        $units = EntryPointResolver::resolve([
            new ProjectUnit('node', 'package.json', 'h', [
                'entry_points' => [],
                'public_entry_points' => ['dist/index.d.ts', 'dist/esm/index.d.mts', 'dist/cjs/main.d.cts'],
            ]),
            new ProjectUnit('typescript', 'tsconfig.json', 'h', ['out_dir' => 'dist', 'root_dir' => 'src']),
        ], []);

        assertSame([
            'dist/cjs/main.d.cts',
            'dist/esm/index.d.mts',
            'dist/index.d.ts',
            'src/cjs/main.cjs',
            'src/cjs/main.cts',
            'src/esm/index.mjs',
            'src/esm/index.mts',
            'src/index.js',
            'src/index.jsx',
            'src/index.ts',
            'src/index.tsx',
        ], $units[0]->metadata['public_entry_points']);
    }

    /**
     * A page's references resolve to the one scanned file each names; the
     * ones that name none, or several, become warnings at the page's line
     * and add no entry point.
     */
    public function testHtmlUnitsGetTheirResolvedScriptsAndMissesBecomeWarnings(): void
    {
        $diagnostics = [new DiscoveryDiagnostic('warning', 'DISCOVERY_FILE_TOO_LARGE', 'kept', 'big.js')];
        $units = EntryPointResolver::resolve([
            new ProjectUnit('html', 'web/index.html', 'h', ['script_refs' => [
                ['url' => './app.mjs', 'line' => 3],
                ['url' => '/shared.js', 'line' => 7],
                ['url' => './gone.js', 'line' => 9],
                ['url' => 'https://cdn.example/x.js', 'line' => 10],
            ]]),
        ], self::files(['shared.js', 'web/app.mjs', 'web/shared.js']), [], $diagnostics);

        assertSame(['web/app.mjs'], $units[0]->metadata['entry_points']);
        assertSame(4, count($units[0]->metadata['script_refs']));
        assertSame(3, count($diagnostics));
        assertSame('DISCOVERY_FILE_TOO_LARGE', $diagnostics[0]->code);
        [$ambiguous, $missing] = [$diagnostics[1], $diagnostics[2]];
        assertSame(['warning', 'DISCOVERY_HTML_SCRIPT_AMBIGUOUS', 'web/index.html'], [$ambiguous->severity, $ambiguous->code, $ambiguous->relativePath]);
        self::assertStringContainsString('web/index.html:7', $ambiguous->message);
        self::assertStringContainsString('/shared.js', $ambiguous->message);
        self::assertStringContainsString('shared.js, web/shared.js', $ambiguous->message);
        assertSame(['warning', 'DISCOVERY_HTML_SCRIPT_UNRESOLVED', 'web/index.html'], [$missing->severity, $missing->code, $missing->relativePath]);
        self::assertStringContainsString('web/index.html:9', $missing->message);
        self::assertStringContainsString('./gone.js', $missing->message);
    }

    /** A mapped file that is missing, and a path above the root, each say why; a malformed ref is skipped. */
    public function testEachMissSaysWhy(): void
    {
        $diagnostics = [];
        EntryPointResolver::resolve([
            new ProjectUnit('html', 'index.html', 'h', ['script_refs' => [
                ['url' => '/static/gone.mjs', 'line' => 2],
                ['url' => '../../up.js', 'line' => 4],
                ['url' => 42, 'line' => 5],
                'not a ref',
            ]]),
        ], self::files(['gone.mjs']), ['/static/' => 'web/'], $diagnostics);

        assertSame(2, count($diagnostics));
        assertSame('index.html:2 loads /static/gone.mjs, which web_roots maps to web/gone.mjs, and that is not a scanned file.', $diagnostics[0]->message);
        assertSame('index.html:4 loads ../../up.js, which points above the project root.', $diagnostics[1]->message);
        assertSame(['DISCOVERY_HTML_SCRIPT_UNRESOLVED', 'DISCOVERY_HTML_SCRIPT_UNRESOLVED'], [$diagnostics[0]->code, $diagnostics[1]->code]);
    }

    /** A trailing `**` spans the rest of the path, and `?` stands for one character within a segment. */
    public function testEntryGlobsSpanDirectoriesAndSingleCharacters(): void
    {
        $units = EntryPointResolver::resolve([
            new ProjectUnit('node', 'package.json', 'h', ['entry_globs' => ['jobs/**', 'bin/run?.ts']]),
        ], self::files(['bin/run1.ts', 'bin/run10.ts', 'jobs/a/b.ts', 'jobs/c.ts', 'other.ts']));

        assertSame(['bin/run1.ts', 'jobs/a/b.ts', 'jobs/c.ts'], $units[0]->metadata['entry_points']);
    }

    /** A configured web root decides a reference the heuristic would find ambiguous. */
    public function testAConfiguredWebRootReachesResolution(): void
    {
        $diagnostics = [];
        $units = EntryPointResolver::resolve([
            new ProjectUnit('html', 'web/public/index.html', 'h', ['script_refs' => [['url' => '/static/app.mjs', 'line' => 1]]]),
        ], self::files(['static/app.mjs', 'web/public/app.mjs']), ['/static/' => 'web/public/'], $diagnostics);

        assertSame(['web/public/app.mjs'], $units[0]->metadata['entry_points']);
        assertSame([], $diagnostics);
    }

    /** Units of every other kind are left alone, and a page loading the same file twice lists it once. */
    public function testOnlyHtmlUnitsAreResolvedAndEachPathOnce(): void
    {
        $units = EntryPointResolver::resolve([
            new ProjectUnit('html', 'index.html', 'h', ['script_refs' => [
                ['url' => 'b.js', 'line' => 1],
                ['url' => './a.js', 'line' => 2],
                ['url' => '/a.js', 'line' => 3],
            ]]),
            new ProjectUnit('node', 'package.json', 'h', ['entry_points' => ['bin.js'], 'script_refs' => [['url' => './a.js', 'line' => 1]]]),
        ], self::files(['a.js', 'b.js']));

        assertSame(['a.js', 'b.js'], $units[0]->metadata['entry_points']);
        assertSame(['bin.js'], $units[1]->metadata['entry_points']);
    }

    /**
     * A markdown page inside a VitePress or VuePress site is compiled into a
     * Vue component, so the modules its script imports are entered through
     * it: a relative path from the page, `@theme/` under the site's theme, a
     * path missing its extension as Vite completes it. A bare package and a
     * path that names no scanned file add nothing, and a page outside every
     * site is plain text.
     */
    public function testMarkdownPagesInASiteEnterTheModulesTheirScriptsImport(): void
    {
        $imports = static fn(array $specifiers): array => ['script_imports' => array_map(
            static fn(string $specifier): array => ['specifier' => $specifier, 'line' => 1],
            $specifiers,
        )];
        $units = EntryPointResolver::resolve([
            new ProjectUnit('markdown', 'site/content/blog/post.md', 'h', $imports([
                '../../.vitepress/theme/components/Card.vue',
                '@theme/components/Chart',
                'vue',
                './gone.vue',
                '../../../../above.vue',
            ])),
            new ProjectUnit('markdown', 'docs/guide/intro.md', 'h', $imports(['../.vuepress/components/Demo.vue'])),
            new ProjectUnit('markdown', 'notes/todo.md', 'h', $imports(['../site/.vitepress/theme/components/Card.vue'])),
        ], self::files([
            'docs/.vuepress/config.ts',
            'docs/.vuepress/components/Demo.vue',
            'site/.vitepress/config.mts',
            'site/.vitepress/theme/components/Card.vue',
            'site/.vitepress/theme/components/Chart.vue',
        ]));

        assertSame([
            'site/.vitepress/theme/components/Card.vue',
            'site/.vitepress/theme/components/Chart.vue',
        ], $units[0]->metadata['entry_points']);
        assertSame(['docs/.vuepress/components/Demo.vue'], $units[1]->metadata['entry_points']);
        // `notes/` is in no site, so its page is never compiled.
        assertSame([], $units[2]->metadata['entry_points']);
    }

    /** An ignored page is never read, so it adds no entry point and reports nothing. */
    public function testAnIgnoredPageAddsNothing(): void
    {
        $root = sys_get_temp_dir() . '/knossos-html-ignored-' . bin2hex(random_bytes(6));
        mkdir($root . '/frontend', 0700, true);
        try {
            file_put_contents($root . '/frontend/index.html', "<script src=\"./app.js\"></script>\n<script src=\"./missing.js\"></script>\n");
            file_put_contents($root . '/frontend/app.js', "export const x = 1;\n");

            $result = (new ProjectDiscoverer(new DiscoveryConfig([$root], ignorePatterns: ['frontend/index.html'])))->discover($root);

            assertSame([], array_values(array_filter($result->units, static fn(ProjectUnit $unit): bool => $unit->kind === 'html')));
            assertSame([], $result->diagnostics);
        } finally {
            @unlink($root . '/frontend/index.html');
            @unlink($root . '/frontend/app.js');
            @rmdir($root . '/frontend');
            @rmdir($root);
        }
    }

    /**
     * Discovered files with the given paths, as the walk lists them.
     *
     * @param list<string> $paths
     * @return list<DiscoveredFile>
     */
    private static function files(array $paths): array
    {
        return array_map(static fn(string $path): DiscoveredFile => new DiscoveredFile($path, '/project/' . $path, 'typescript', 1, 0, 'h'), $paths);
    }
}
