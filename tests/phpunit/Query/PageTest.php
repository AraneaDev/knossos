<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\Page;
use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/** A page read one row past its limit: the rows up to it, and whether the extra row came. */
final class PageTest extends KnossosTestCase
{
    #[Group('query')]
    public function testThePageIsCutAtItsLimitAndSaysWhetherMoreCame(): void
    {
        assertSame([['a', 'b'], true], Page::cut(['a', 'b', 'c'], 2));
        assertSame([['a', 'b'], false], Page::cut(['a', 'b'], 2));
        assertSame([['a'], false], Page::cut(['a'], 2));
        assertSame([[], false], Page::cut([], 2));
    }
}
