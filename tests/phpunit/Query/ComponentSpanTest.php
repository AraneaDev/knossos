<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ComponentSpan;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A component's own lines, hashed by their text so a note on it survives an
 * edit that only moves them.
 */
#[Group('query')]
final class ComponentSpanTest extends KnossosTestCase
{
    public function testTheSameLinesAtAnotherOffsetHashTheSame(): void
    {
        $before = "<?php\nfunction m() {\n    return 1;\n}\n";
        $after = "<?php\n\n\n// moved down\nfunction m() {\n    return 1;\n}\n";

        self::assertSame(ComponentSpan::hash($before, 2, 4), ComponentSpan::hash($after, 5, 7));
        self::assertSame(hash('sha256', "function m() {\n    return 1;\n}"), ComponentSpan::hash($before, 2, 4));
    }

    public function testOneChangedCharacterChangesTheHash(): void
    {
        $before = "<?php\nfunction m() {\n    return 1;\n}\n";
        $after = "<?php\nfunction m() {\n    return 2;\n}\n";

        self::assertNotSame(ComponentSpan::hash($before, 2, 4), ComponentSpan::hash($after, 2, 4));
        // Lines outside the span do not count.
        self::assertSame(ComponentSpan::hash($before, 1, 2), ComponentSpan::hash($after, 1, 2));
    }

    public function testLinesPastTheEndHashAsTheLinesThereAre(): void
    {
        $contents = "<?php\nfunction m() {}\n";

        self::assertSame(ComponentSpan::hash($contents, 2, 2), ComponentSpan::hash($contents, 2, 40));
        self::assertSame(hash('sha256', ''), ComponentSpan::hash($contents, 5, 9));
    }
}
