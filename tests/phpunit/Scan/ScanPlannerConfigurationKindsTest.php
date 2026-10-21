<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Discovery\UnitKind;
use Knossos\Scan\ScanPlanner;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The unit kinds each language's configuration hash covers are part of every
 * contribution cache key, so they stay exactly these strings: a kind renamed,
 * added or dropped would invalidate every cached contribution.
 */
#[Group('scan')]
final class ScanPlannerConfigurationKindsTest extends KnossosTestCase
{
    public function testTheConfigurationKindsAreTheCacheKeyStrings(): void
    {
        $kinds = array_map(
            static fn(array $kinds): array => array_map(static fn(UnitKind $kind): string => $kind->value, $kinds),
            ScanPlanner::CONFIGURATION_KINDS,
        );

        assertSame([
            'php' => ['composer', 'knossos'],
            'typescript' => ['node', 'typescript', 'tool_config', 'knossos'],
            'python' => ['python', 'requirements', 'knossos'],
            'rust' => ['cargo', 'knossos'],
        ], $kinds);
    }
}
