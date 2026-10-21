<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;

use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The query facade's methods carry one docblock each, so a static analyser
 * reads every array parameter's element type: a second docblock stacked
 * above the one PHP keeps is never read.
 */
final class QueryFacadeDocblockTest extends TestCase
{
    /** No docblock in the facade stands directly above another: PHP and PHPStan read only the second. */
    #[Group('query')]
    public function testNoDocblockIsStackedOnAnother(): void
    {
        $file = (string) (new ReflectionClass(ArchitectureQueryService::class))->getFileName();
        preg_match_all('#\*/\s*/\*\*#', (string) file_get_contents($file), $stacked);

        assertSame([], $stacked[0]);
    }
}
