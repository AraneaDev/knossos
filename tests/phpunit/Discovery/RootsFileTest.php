<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Discovery;

use Knossos\Discovery\RootsFile;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

final class RootsFileTest extends KnossosTestCase
{
    /** A moved checkout swaps its old grant for the new one in one write, keeping every other root. */
    #[Group('discovery')]
    public function testReplaceSwapsOneRootAndKeepsTheRest(): void
    {
        $directory = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($directory, 0o700, true);
        try {
            $file = new RootsFile($directory . '/roots.json');
            $file->write(['/srv/other', '/root/project/']);

            self::assertSame(['added' => true, 'removed' => true], $file->replace('/root/project', '/home/me/project'));
            self::assertSame(['/srv/other', '/home/me/project'], $file->read());
            self::assertSame(['added' => false, 'removed' => false], $file->replace('/root/project', '/home/me/project'), 'A second run has nothing left to do.');
        } finally {
            $this->removeTempTree($directory);
        }
    }

    /** No old entry to drop (a roots file that never listed it) still adds the new root. */
    #[Group('discovery')]
    public function testReplaceWithNothingToRemoveOnlyAdds(): void
    {
        $directory = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($directory, 0o700, true);
        try {
            $file = new RootsFile($directory . '/roots.json');

            self::assertSame(['added' => true, 'removed' => false], $file->replace(null, '/home/me/project'));
            self::assertSame(['/home/me/project'], $file->read());
        } finally {
            $this->removeTempTree($directory);
        }
    }
}
