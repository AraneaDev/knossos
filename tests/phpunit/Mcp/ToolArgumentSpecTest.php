<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Mcp;

use Knossos\Mcp\ToolArgumentSpec;
use Knossos\Tests\Phpunit\KnossosTestCase;
use LogicException;
use PHPUnit\Framework\Attributes\Group;

/** A tool's argument spec is its catalogue schema, and a number the schema does not carry is a programming error. */
final class ToolArgumentSpecTest extends KnossosTestCase
{
    #[Group('mcp')]
    public function testTheSpecReadsTheDefaultsAndBoundsTheSchemaAdvertises(): void
    {
        $impact = ToolArgumentSpec::for('impact_analysis');

        assertSame(['default' => 6, 'minimum' => 1, 'maximum' => 8], $impact->bounds('max_depth'));
        assertSame(true, $impact->booleanDefault('seed_members'));
        assertSame(false, $impact->booleanDefault('rings'));
        assertSame('possible', $impact->stringDefault('min_confidence'));
        assertSame(7, $impact->integer(['max_depth' => 7], 'max_depth'));
        assertSame(6, $impact->integer([], 'max_depth'));
        assertSame('certain', $impact->string(['min_confidence' => ' certain '], 'min_confidence'));
        assertSame(true, in_array('rings', $impact->properties(), true));
    }

    #[Group('mcp')]
    public function testAValueOutsideTheAdvertisedBoundsIsRefusedWithThem(): void
    {
        $error = captureThrows(static fn() => ToolArgumentSpec::for('impact_analysis')->integer(['max_depth' => 9], 'max_depth'), \InvalidArgumentException::class);

        assertSame('max_depth must be an integer between 1 and 8.', $error->getMessage());
    }

    #[Group('mcp')]
    public function testANumberTheSchemaDoesNotCarryIsAProgrammingError(): void
    {
        $spec = ToolArgumentSpec::for('scan_project');

        assertSame('scan_project.max_files declares no integer default and bounds.', captureThrows(static fn() => $spec->bounds('max_files'), LogicException::class)->getMessage());
        assertSame('scan_project declares no argument nope.', captureThrows(static fn() => $spec->booleanDefault('nope'), LogicException::class)->getMessage());
        assertSame('scan_project.path declares no string default.', captureThrows(static fn() => $spec->stringDefault('path'), LogicException::class)->getMessage());
        assertSame('No tool named nope in the catalogue.', captureThrows(static fn() => ToolArgumentSpec::for('nope'), LogicException::class)->getMessage());
    }
}
