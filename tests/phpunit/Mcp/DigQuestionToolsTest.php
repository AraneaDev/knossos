<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Mcp;

use Knossos\Mcp\ToolCatalog;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/** The pane's note reminder names question tools by string; a renamed tool would switch it off silently. */
final class DigQuestionToolsTest extends KnossosTestCase
{
    #[Group('mcp')]
    public function testEveryQuestionToolTheReminderWatchesExists(): void
    {
        $source = (string) file_get_contents(self::repositoryRoot() . '/hooks/mod/dig.ts');
        self::assertSame(1, preg_match('/const QUESTIONS(?:: [\w<>]+)? = new Set\(\[(.*?)\]\)/s', $source, $match), 'dig.ts declares no QUESTIONS set.');
        preg_match_all("/'([^'\s]+)'/", $match[1], $names);
        $registered = array_column(ToolCatalog::definitions(), 'name');

        self::assertNotSame([], $names[1]);
        self::assertSame([], array_values(array_diff($names[1], $registered)), 'dig.ts names tools the catalogue does not have.');
    }
}
