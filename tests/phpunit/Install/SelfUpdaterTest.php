<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Install;

use Knossos\Install\GitHubReleaseSource;
use Knossos\Install\Installation;
use Knossos\Install\ReleaseProbe;
use Knossos\Install\SelfUpdater;
use Knossos\Install\UpdateCheck;
use Knossos\Install\VersionSwitch;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Update, check and rollback against a real data directory and a local release directory. */
final class SelfUpdaterTest extends KnossosTestCase
{
    use InstallFixtures;

    private const PLATFORM = 'linux-x86_64';

    #[Group('install')]
    public function testItUpdatesToTheNewestRelease(): void
    {
        $data = $this->installed('d', '1.0.0', '0.5.0');
        $this->publish($this->work . '/r', '2.0.0');
        $probe = new FakeProbe();

        $result = $this->updater($data, $probe)->update(null);

        assertSame(['status' => 'updated', 'from' => '1.0.0', 'to' => '2.0.0'], $result);
        $switch = new VersionSwitch($data);
        assertSame('2.0.0', $switch->current());
        assertSame('1.0.0', $switch->previous());
        assertSame(['1.0.0', '2.0.0'], array_map('basename', glob($data . '/versions/*')));
        assertSame(1, $probe->doctorRuns);
        assertSame('2.0.0', (new Installation($data, $data))->record()['version']);
        assertSame('2.0.0', (new UpdateCheck($data))->read()['latest']);
        assertSame(false, file_exists($data . '/self-update.tmp'));
    }

    #[Group('install')]
    public function testAnUpToDateInstallationIsLeftAlone(): void
    {
        $data = $this->installed('d', '1.0.0');
        $this->publish($this->work . '/r', '1.0.0');
        $probe = new FakeProbe();

        assertSame(['status' => 'current', 'from' => '1.0.0', 'to' => '1.0.0'], $this->updater($data, $probe)->update(null));
        assertSame(0, $probe->doctorRuns);
        assertSame('1.0.0', (new VersionSwitch($data))->current());
    }

    #[Group('install')]
    public function testAnOlderLatestIsNotInstalledUnlessAskedFor(): void
    {
        $data = $this->installed('d', '2.0.0');
        $this->publish($this->work . '/r', '1.0.0');

        assertSame('current', $this->updater($data)->update(null)['status']);
        assertSame('current', $this->updater($data)->update('v2.0.0')['status']);
        $result = $this->updater($data)->update('1.0.0');
        assertSame(['status' => 'updated', 'from' => '2.0.0', 'to' => '1.0.0'], $result);
    }

    #[Group('install')]
    public function testAChecksumMismatchChangesNothing(): void
    {
        $data = $this->installed('d', '1.0.0');
        $asset = $this->publish($this->work . '/r', '2.0.0');
        file_put_contents($this->work . '/r/' . $asset, 'tampered', FILE_APPEND);
        $probe = new FakeProbe();

        $this->refused(fn() => $this->updater($data, $probe)->update(null), 'Checksum mismatch');

        assertSame('1.0.0', (new VersionSwitch($data))->current());
        assertSame(['1.0.0'], array_map('basename', glob($data . '/versions/*')));
        assertSame(0, $probe->doctorRuns);
        assertSame(false, file_exists($data . '/self-update.tmp'));
    }

    #[Group('install')]
    public function testAReleaseWithoutABuildForThePlatformIsRefused(): void
    {
        $data = $this->installed('d', '1.0.0');
        $this->publish($this->work . '/r', '2.0.0');
        file_put_contents($this->work . '/r/SHA256SUMS', str_repeat('a', 64) . "  knossos-2.0.0-darwin-arm64.tar.gz\n");

        $this->refused(fn() => $this->updater($data)->update(null), 'has no entry');

        assertSame('1.0.0', (new VersionSwitch($data))->current());
    }

    #[Group('install')]
    public function testAMissingAssetIsRefused(): void
    {
        $data = $this->installed('d', '1.0.0');
        $this->publish($this->work . '/r', '2.0.0');
        unlink($this->work . '/r/SHA256SUMS');

        $this->refused(fn() => $this->updater($data)->update(null), 'not found');
    }

    #[Group('install')]
    public function testAFailingDoctorLeavesTheCurrentReleaseInPlace(): void
    {
        $data = $this->installed('d', '1.0.0');
        $this->publish($this->work . '/r', '2.0.0');
        $probe = new FakeProbe(doctorFailure: 'worker.python: broken');

        $error = self::captureThrown(fn() => $this->updater($data, $probe)->update(null), \Knossos\Install\InstallException::class);

        assertSame(true, str_contains($error->getMessage(), 'worker.python: broken'));
        assertSame('1.0.0', (new VersionSwitch($data))->current());
        assertSame(['1.0.0'], array_map('basename', glob($data . '/versions/*')));
    }

    #[Group('install')]
    public function testARelinkedReleaseThatDoesNotStartIsSwitchedBack(): void
    {
        $data = $this->installed('d', '1.0.0');
        $this->publish($this->work . '/r', '2.0.0');
        $installJson = (string) file_get_contents($data . '/install.json');

        $this->refused(fn() => $this->updater($data, new FakeProbe(versionWorks: false))->update(null), '1.0.0 is current again');

        $switch = new VersionSwitch($data);
        assertSame('1.0.0', $switch->current());
        assertSame($installJson, file_get_contents($data . '/install.json'));
        assertSame(null, $switch->previous());
    }

    #[Group('install')]
    public function testWithoutACurrentLinkThereIsNothingToUpdate(): void
    {
        mkdir($this->work . '/empty');

        $this->refused(fn() => $this->updater($this->work . '/empty')->update(null), 'no installed release');
        $this->refused(fn() => $this->updater($this->work . '/empty')->rollback(), 'no installed release');
    }

    #[Group('install')]
    public function testRollbackSwitchesToTheKeptVersionAndBack(): void
    {
        $data = $this->installed('d', '1.0.0');
        $this->publish($this->work . '/r', '2.0.0');
        $updater = $this->updater($data);
        $updater->update(null);

        assertSame(['status' => 'rolled back', 'from' => '2.0.0', 'to' => '1.0.0'], $updater->rollback());
        $switch = new VersionSwitch($data);
        assertSame('1.0.0', $switch->current());
        assertSame('2.0.0', $switch->previous());
        assertSame('1.0.0', (new Installation($data, $data))->record()['version']);

        assertSame('2.0.0', $updater->rollback()['to']);
    }

    #[Group('install')]
    public function testUpdatingAgainAfterARollbackReusesTheKeptVersion(): void
    {
        $data = $this->installed('d', '1.0.0');
        $this->publish($this->work . '/r', '2.0.0');
        $probe = new FakeProbe();
        $updater = $this->updater($data, $probe);
        $updater->update(null);
        $updater->rollback();

        assertSame('updated', $updater->update(null)['status']);
        assertSame(1, $probe->doctorRuns);
    }

    #[Group('install')]
    public function testRollbackWithNothingKeptSaysSo(): void
    {
        $data = $this->installed('d', '1.0.0');

        $this->refused(fn() => $this->updater($data)->rollback(), 'no previous version');

        assertSame('1.0.0', (new VersionSwitch($data))->current());
    }

    #[Group('install')]
    public function testASecondRunWhileOneIsActiveIsRefused(): void
    {
        $data = $this->installed('d', '1.0.0');
        $this->publish($this->work . '/r', '2.0.0');
        $lock = fopen($data . '/self-update.lock', 'c');
        self::assertNotFalse($lock);
        flock($lock, LOCK_EX | LOCK_NB);

        $this->refused(fn() => $this->updater($data)->update(null), 'Another knossos self-update is running');
        $this->refused(fn() => $this->updater($data)->rollback(), 'Another knossos self-update is running');

        flock($lock, LOCK_UN);
        assertSame('updated', $this->updater($data)->update(null)['status']);
    }

    #[Group('install')]
    public function testCheckReportsAndCachesTheLatestVersion(): void
    {
        $data = $this->installed('d', '1.0.0');
        $this->publish($this->work . '/r', '1.1.0');

        assertSame(['current' => '1.0.0', 'latest' => '1.1.0', 'update' => true], $this->updater($data)->check('1.0.0'));
        assertSame('1.1.0', (new UpdateCheck($data))->read()['latest']);
        assertSame(['current' => '1.1.0', 'latest' => '1.1.0', 'update' => false], $this->updater($data)->check('1.1.0'));
        assertSame('1.0.0', (new VersionSwitch($data))->current());
    }

    #[Group('install')]
    public function testAWriteFailingAfterTheLinkMovedPutsEverythingBack(): void
    {
        $data = $this->installed('d', '1.0.0');
        $this->publish($this->work . '/r', '2.0.0');
        // A directory where install.json belongs: the rename over it fails after the link has moved.
        unlink($data . '/install.json');
        mkdir($data . '/install.json');

        $this->refused(fn() => $this->updater($data)->update(null), 'Could not write');

        assertSame('1.0.0', (new VersionSwitch($data))->current());
        assertSame(false, is_file($data . '/previous-version'));
    }

    #[Group('install')]
    public function testAFailedCheckStillRecordsTheAttempt(): void
    {
        $data = $this->installed('d', '1.0.0');
        (new UpdateCheck($data))->record('1.2.0', 1_000_000);
        mkdir($this->work . '/r');

        $this->refused(fn() => $this->updater($data)->check('1.0.0'), 'No release asset');

        $cached = (new UpdateCheck($data))->read();
        assertSame('1.2.0', $cached['latest']);
        assertSame(false, (new UpdateCheck($data))->due());
    }

    private function updater(string $data, ?ReleaseProbe $probe = null): SelfUpdater
    {
        return new SelfUpdater(
            new Installation($data, $data . '/versions/1.0.0'),
            new GitHubReleaseSource($this->work . '/r', null, self::PLATFORM),
            $probe ?? new FakeProbe(),
            self::PLATFORM,
        );
    }
}
