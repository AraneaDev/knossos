<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Install;

use Knossos\Install\VersionSwitch;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/** The versions directory, the `current` link and what is kept for rollback. */
final class VersionSwitchTest extends KnossosTestCase
{
    use InstallFixtures;

    #[Group('install')]
    public function testItReadsWhatIsInstalled(): void
    {
        $data = $this->installed('d', '1.0.0', '0.9.0');
        $switch = new VersionSwitch($data);

        assertSame('1.0.0', $switch->current());
        assertSame(true, $switch->isComplete('0.9.0'));
        assertSame(false, $switch->isComplete('0.8.0'));
        assertSame(null, $switch->previous());
        file_put_contents($data . '/previous-version', "0.9.0\n");
        assertSame('0.9.0', $switch->previous());
        file_put_contents($data . '/previous-version', "0.1.0\n");
        assertSame(null, $switch->previous());
        file_put_contents($data . '/previous-version', "../x\n");
        assertSame(null, $switch->previous());
    }

    #[Group('install')]
    public function testCurrentIsNullWhenItIsMissingOrPointsElsewhere(): void
    {
        mkdir($this->work . '/e');
        $switch = new VersionSwitch($this->work . '/e');
        assertSame(null, $switch->current());
        symlink('/etc', $this->work . '/e/current');
        assertSame(null, $switch->current());
    }

    #[Group('install')]
    public function testSwitchingMovesCurrentAndRecordsThePreviousVersion(): void
    {
        $data = $this->installed('d', '1.0.0', '2.0.0');
        $switch = new VersionSwitch($data);

        $switch->switchTo('2.0.0', '1.0.0');

        assertSame('versions/2.0.0', readlink($data . '/current'));
        assertSame('1.0.0', $switch->previous());
        $record = json_decode((string) file_get_contents($data . '/install.json'), true);
        assertSame('native', $record['mode']);
        assertSame('2.0.0', $record['version']);
        assertSame(null, $record['image']);
        assertSame([], glob($data . '/*.tmp.*'));

        $switch->switchTo('1.0.0', null);
        assertSame(false, is_file($data . '/previous-version'));
    }

    #[Group('install')]
    public function testItRefusesToSwitchToAnIncompleteVersion(): void
    {
        $data = $this->installed('d', '1.0.0');
        mkdir($data . '/versions/2.0.0');

        $this->refused(fn() => (new VersionSwitch($data))->switchTo('2.0.0', '1.0.0'), 'not completely installed');

        assertSame('1.0.0', (new VersionSwitch($data))->current());
    }

    #[Group('install')]
    public function testASnapshotPutsBackTheLinkAndBothFiles(): void
    {
        $data = $this->installed('d', '1.0.0', '2.0.0');
        $switch = new VersionSwitch($data);
        $before = $switch->snapshot();
        $installJson = (string) file_get_contents($data . '/install.json');

        $switch->switchTo('2.0.0', '1.0.0');
        $switch->restore($before);

        assertSame('1.0.0', $switch->current());
        assertSame($installJson, file_get_contents($data . '/install.json'));
        assertSame(false, is_file($data . '/previous-version'));

        $empty = new VersionSwitch($this->work . '/fresh');
        mkdir($this->work . '/fresh');
        $snapshot = $empty->snapshot();
        assertSame(null, $snapshot['link']);
        symlink('versions/x', $this->work . '/fresh/current');
        $empty->restore($snapshot);
        assertSame(false, is_link($this->work . '/fresh/current'));
    }

    #[Group('install')]
    public function testPruneKeepsOnlyTheNamedVersionsAndOtherRunsPartials(): void
    {
        $data = $this->installed('d', '3.0.0', '2.0.0', '1.0.0');
        mkdir($data . '/versions/4.0.0.partial.99');
        (new VersionSwitch($data))->prune(['3.0.0', '2.0.0']);

        assertSame(['2.0.0', '3.0.0', '4.0.0.partial.99'], array_map('basename', glob($data . '/versions/*')));
    }

    #[Group('install')]
    public function testAnArchiveIsUnpackedIntoAPartialAndAdopted(): void
    {
        $release = $this->work . '/release';
        $asset = $this->publish($release, '2.0.0');
        $data = $this->installed('d', '1.0.0');
        $switch = new VersionSwitch($data);

        $partial = $switch->unpack($release . '/' . $asset, '2.0.0');
        assertSame(true, str_contains($partial, '.partial.'));
        assertSame(true, is_executable($partial . '/bin/knossos'));
        $switch->adopt($partial, '2.0.0');

        assertSame(true, $switch->isComplete('2.0.0'));
        assertSame(false, is_dir($partial));
        assertSame('1.0.0', $switch->current());
    }

    #[Group('install')]
    public function testAdoptingOverACompleteVersionKeepsTheExistingOne(): void
    {
        $release = $this->work . '/release';
        $asset = $this->publish($release, '1.0.0');
        $data = $this->installed('d', '1.0.0');
        file_put_contents($data . '/versions/1.0.0/marker', 'x');
        $switch = new VersionSwitch($data);

        $switch->adopt($switch->unpack($release . '/' . $asset, '1.0.0'), '1.0.0');

        assertSame(true, is_file($data . '/versions/1.0.0/marker'));
        assertSame([], glob($data . '/versions/*.partial.*'));
    }

    #[Group('install')]
    public function testAdoptingOverAnIncompleteDirectoryReplacesIt(): void
    {
        $release = $this->work . '/release';
        $asset = $this->publish($release, '2.0.0');
        $data = $this->installed('d', '1.0.0');
        mkdir($data . '/versions/2.0.0/junk', 0o755, true);
        $switch = new VersionSwitch($data);

        $switch->adopt($switch->unpack($release . '/' . $asset, '2.0.0'), '2.0.0');

        assertSame(true, $switch->isComplete('2.0.0'));
        assertSame(false, is_dir($data . '/versions/2.0.0/junk'));
    }

    #[Group('install')]
    public function testAnArchiveWithAnUnexpectedTopDirectoryIsRefused(): void
    {
        $release = $this->work . '/release';
        $asset = $this->publish($release, '2.0.0', 'linux-x86_64', '9.9.9');
        $data = $this->installed('d', '1.0.0');

        $this->refused(fn() => (new VersionSwitch($data))->unpack($release . '/' . $asset, '2.0.0'), 'Unsafe or unexpected entry');

        assertSame([], glob($data . '/versions/*.partial.*'));
    }

    #[Group('install')]
    public function testAnArchiveMemberWithDotDotIsRefused(): void
    {
        $stage = $this->work . '/evil';
        mkdir($stage . '/knossos-2.0.0', 0o755, true);
        file_put_contents($stage . '/knossos-2.0.0/a', 'a');
        exec(sprintf('tar -czf %s -C %s --transform %s knossos-2.0.0/a', escapeshellarg($this->work . '/evil.tar.gz'), escapeshellarg($stage), escapeshellarg('s,knossos-2.0.0/a,knossos-2.0.0/../a,')));
        $data = $this->installed('d', '1.0.0');

        $this->refused(fn() => (new VersionSwitch($data))->unpack($this->work . '/evil.tar.gz', '2.0.0'), 'Unsafe or unexpected entry');
    }

    #[Group('install')]
    public function testAnAbsoluteMemberIsRefused(): void
    {
        $stage = $this->work . '/abs';
        mkdir($stage, 0o755, true);
        file_put_contents($stage . '/a', 'a');
        exec(sprintf('tar -czf %s -P -C %s --transform %s a', escapeshellarg($this->work . '/abs.tar.gz'), escapeshellarg($stage), escapeshellarg('s,^a,/tmp/knossos-abs-a,')));
        $data = $this->installed('d', '1.0.0');

        $this->refused(fn() => (new VersionSwitch($data))->unpack($this->work . '/abs.tar.gz', '2.0.0'), 'Unsafe or unexpected entry');
    }

    #[Group('install')]
    public function testSomethingThatIsNotAnArchiveIsRefused(): void
    {
        file_put_contents($this->work . '/bad.tar.gz', 'not an archive');
        $data = $this->installed('d', '1.0.0');

        $this->refused(fn() => (new VersionSwitch($data))->unpack($this->work . '/bad.tar.gz', '2.0.0'), 'not a readable archive');
    }

    #[Group('install')]
    public function testAnArchiveWithoutABinaryIsNotARelease(): void
    {
        $stage = $this->work . '/thin';
        mkdir($stage . '/knossos-2.0.0', 0o755, true);
        file_put_contents($stage . '/knossos-2.0.0/VERSION', "2.0.0\n");
        exec(sprintf('tar -czf %s -C %s knossos-2.0.0', escapeshellarg($this->work . '/thin.tar.gz'), escapeshellarg($stage)));
        $data = $this->installed('d', '1.0.0');

        $this->refused(fn() => (new VersionSwitch($data))->unpack($this->work . '/thin.tar.gz', '2.0.0'), 'does not hold a complete release');

        assertSame([], glob($data . '/versions/*.partial.*'));
    }
}
