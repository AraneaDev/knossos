<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity\Fixtures;

use Knossos\TestFidelity\Fixtures\FixtureFiles;
use Knossos\Tests\Phpunit\KnossosTestCase;

/** The fixture walk's edges that the tool tests do not reach. */
final class FixtureFilesTest extends KnossosTestCase
{
    public function testARootThatIsGoneHoldsNoFixture(): void
    {
        $root = self::scratchPath('knossos-fixture-walk') . '/gone';

        self::assertSame(['files' => [], 'skipped' => 0, 'truncated' => false], FixtureFiles::walk($root, [], static fn(): bool => false));
    }
}
