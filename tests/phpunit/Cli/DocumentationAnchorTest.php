<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Cli;

use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/** The link check's heading anchors follow GitHub's rule, which the documentation site's slugs copy. */
final class DocumentationAnchorTest extends KnossosTestCase
{
    #[Group('documentation')]
    public function testEmphasisUnderscoresAreDroppedButThoseInsideWordsAndCodeAreKept(): void
    {
        $tree = sys_get_temp_dir() . '/knossos-anchors-' . bin2hex(random_bytes(6));
        foreach (['docs', 'skills', 'plugins'] as $directory) {
            mkdir($tree . '/' . $directory, 0o777, true);
        }
        file_put_contents($tree . '/README.md', "# Home\n");
        file_put_contents($tree . '/docs/a.md', "[a](page.md#em-and-x) [b](page.md#file_context-tool) [c](page.md#_a_-and-snake_case)\n");
        file_put_contents($tree . '/docs/page.md', "# Page\n\n## _Em_ and *x*\n\n## `file_context` tool\n\n## `_a_` and snake_case\n");
        try {
            [$exit, , $errors] = $this->runFixtureCommandOutput([PHP_BINARY, self::repositoryRoot() . '/tools/documentation-check.php', '--root=' . $tree]);
        } finally {
            array_map('unlink', glob($tree . '/docs/*') ?: []);
            @unlink($tree . '/README.md');
            foreach (['docs', 'skills', 'plugins'] as $directory) {
                @rmdir($tree . '/' . $directory);
            }
            @rmdir($tree);
        }

        self::assertSame('', $errors);
        self::assertSame(0, $exit);
    }
}
