<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Cli;

use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/** The release gate runs the suite once and says what it runs. */
final class ReleaseWorkflowTest extends KnossosTestCase
{
    #[Group('packaging')]
    public function testTheReleaseGateRunsPhpunitOnlyUnderCoverage(): void
    {
        $workflow = (string) file_get_contents(self::repositoryRoot() . '/.github/workflows/release-please.yml');

        self::assertStringContainsString('KNOSSOS_PHPUNIT_IN_COVERAGE=1', $workflow, 'The coverage lane already runs the whole suite; the tests lane must not repeat it.');
        self::assertStringNotContainsString('mutation included', $workflow, 'No quality profile runs mutation testing.');
    }
}
