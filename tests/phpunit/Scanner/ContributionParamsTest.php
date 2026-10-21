<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Scanner\Worker\ContributionParams;
use Knossos\Scanner\Worker\WorkerException;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/** A contribution's params are an object, or the contribution is refused the same way everywhere. */
#[Group('worker')]
final class ContributionParamsTest extends KnossosTestCase
{
    public function testAnObjectPassesAndAnythingElseIsRefused(): void
    {
        assertSame(['nodes' => []], ContributionParams::of(['nodes' => []]));
        foreach ([null, 'text', [1, 2], []] as $params) {
            $error = captureThrows(static fn() => ContributionParams::of($params), WorkerException::class);
            assertSame('WORKER_CONTRIBUTION_INVALID', $error->diagnosticCode);
            assertSame(ContributionParams::NOT_AN_OBJECT, $error->getMessage());
        }
    }
}
