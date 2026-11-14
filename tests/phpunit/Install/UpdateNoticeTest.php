<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Install;

use Knossos\Install\Installation;
use Knossos\Install\UpdateCheck;
use Knossos\Install\UpdateNotice;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/** The line the session brief adds. */
final class UpdateNoticeTest extends KnossosTestCase
{
    use InstallFixtures;

    #[Group('install')]
    public function testTheNoticeNeedsAnInstalledReleaseAndANewerCachedOne(): void
    {
        $data = $this->installed('d', '1.0.0');
        $installed = new Installation($data . '/versions/1.0.0');
        assertSame(null, UpdateNotice::line($installed, '1.0.0'));

        (new UpdateCheck($data))->record('1.0.0');
        assertSame(null, UpdateNotice::line($installed, '1.0.0'));
        (new UpdateCheck($data))->record('0.9.0');
        assertSame(null, UpdateNotice::line($installed, '1.0.0'));
        (new UpdateCheck($data))->record('1.1.0');
        assertSame('knossos 1.1.0 is available (you have 1.0.0): run knossos self-update', UpdateNotice::line($installed, '1.0.0'));

        mkdir($this->work . '/checkout');
        assertSame(null, UpdateNotice::line(new Installation($this->work . '/checkout'), '1.0.0'));
        file_put_contents($data . '/install.json', '{"mode": "container"}');
        assertSame(null, UpdateNotice::line($installed, '1.0.0'));
    }

    #[Group('install')]
    public function testTheOptOutSilencesTheNoticeToo(): void
    {
        $data = $this->installed('d', '1.0.0');
        (new UpdateCheck($data))->record('1.1.0');
        $installed = new Installation($data . '/versions/1.0.0');
        putenv('KNOSSOS_NO_UPDATE_CHECK=1');
        try {
            assertSame(null, UpdateNotice::line($installed, '1.0.0'));
        } finally {
            putenv('KNOSSOS_NO_UPDATE_CHECK');
        }
        assertSame(true, UpdateNotice::line($installed, '1.0.0') !== null);
    }

    #[Group('install')]
    public function testAFailedLookupLeavesNoNotice(): void
    {
        $data = $this->installed('d', '1.0.0');
        (new UpdateCheck($data))->record(null);

        assertSame(null, UpdateNotice::line(new Installation($data . '/versions/1.0.0'), '1.0.0'));
    }
}
