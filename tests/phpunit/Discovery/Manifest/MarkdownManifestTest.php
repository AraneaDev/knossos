<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Discovery\Manifest;

use Knossos\Discovery\Manifest\MarkdownManifest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The modules a markdown page's `<script>` blocks import, each with its line,
 * before anything resolves them. VitePress and VuePress compile a page into
 * a Vue component, so such a block is the page's own script; one shown in a
 * code fence, an inline code span or a comment is text and loads nothing.
 */
#[Group('discovery')]
final class MarkdownManifestTest extends TestCase
{
    /**
     * The reader's `script_imports` for a page.
     *
     * @return list<array{specifier: string, line: int}>
     */
    private static function imports(string $contents): array
    {
        $metadata = (new MarkdownManifest())->read('docs/page.md', '/project/docs/page.md', $contents);
        self::assertArrayNotHasKey('entry_points', $metadata);

        return $metadata['script_imports'];
    }

    public function testReadsTheImportsOfAScriptSetupBlock(): void
    {
        assertSame([
            ['specifier' => '../.vitepress/theme/components/Card.vue', 'line' => 4],
            ['specifier' => 'vue', 'line' => 5],
            ['specifier' => '@theme/components/Chart.vue', 'line' => 6],
        ], self::imports(implode("\n", [
            '# Title',
            '',
            '<script setup lang="ts">',
            "import Card from '../.vitepress/theme/components/Card.vue'",
            "import { ref } from 'vue'",
            "const Chart = defineAsyncComponent(() => import('@theme/components/Chart.vue'))",
            '</script>',
            '',
            '<Card />',
            '',
        ])));
    }

    /** A block indented by up to three spaces is still HTML to markdown; every script on the page counts. */
    public function testReadsEveryScriptBlockAndAShallowIndent(): void
    {
        assertSame([
            ['specifier' => './a.vue', 'line' => 2],
            ['specifier' => './b.vue', 'line' => 6],
        ], self::imports("<script>\nimport A from './a.vue'\n</script>\n\n   <SCRIPT setup>\nimport B from './b.vue'\n</SCRIPT>\n"));
    }

    /**
     * A page about Vue shows a script in a fence, in an inline code span, in
     * an indented code block or in a comment; none of them loads anything.
     */
    public function testIgnoresScriptsShownAsText(): void
    {
        assertSame([], self::imports(implode("\n", [
            '```vue',
            '<script setup>',
            "import A from './a.vue'",
            '</script>',
            '```',
            '',
            '~~~~',
            '<script>',
            "import B from './b.vue'",
            '</script>',
            '~~~~',
            '',
            "Write `<script setup>import C from './c.vue'</script>` in the page.",
            '',
            '    <script>',
            "    import D from './d.vue'",
            '    </script>',
            '',
            '<!--',
            '<script>',
            "import E from './e.vue'",
            '</script>',
            '-->',
            '',
        ])));
    }

    /** A fence closes only on its own character, at least as long, so a shorter or other fence inside it stays text. */
    public function testAFenceClosesOnlyOnAMatchingFence(): void
    {
        assertSame([
            ['specifier' => './after.vue', 'line' => 9],
        ], self::imports(implode("\n", [
            '````md',
            '```',
            '~~~',
            '<script>',
            "import Inside from './inside.vue'",
            '</script>',
            '````',
            '<script>',
            "import After from './after.vue'",
            '</script>',
            '',
        ])));
    }

    /** A script that never closes runs to the end of the page; a page with none records nothing. */
    public function testAnUnclosedScriptRunsToTheEnd(): void
    {
        assertSame([['specifier' => './x.vue', 'line' => 2]], self::imports("<script setup>\nimport X from './x.vue'\n"));
        assertSame([], self::imports("# Just prose\n\nNothing to load.\n"));
    }
}
