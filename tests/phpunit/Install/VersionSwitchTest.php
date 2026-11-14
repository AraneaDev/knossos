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
    public function testPruneKeepsTheNamedVersionsAndLiveUnpacksAndDropsCrashedOnes(): void
    {
        $data = $this->installed('d', '3.0.0', '2.0.0', '1.0.0');
        $mine = '4.0.0.partial.' . getmypid();
        mkdir($data . '/versions/' . $mine);
        // A crashed run's unpack: no process has this id.
        mkdir($data . '/versions/5.0.0.partial.2147483646/bin', 0o755, true);
        (new VersionSwitch($data))->prune(['3.0.0', '2.0.0']);

        assertSame(['2.0.0', '3.0.0', $mine], array_map('basename', glob($data . '/versions/*')));
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

    /**
     * Pack a release whose tree `$build` adds to, and unpack it into a data dir.
     *
     * @param callable(string): void $build receives the release directory
     */
    private function unpackReleaseWith(callable $build, string $data): string
    {
        $stage = $this->work . '/pack-' . bin2hex(random_bytes(3));
        $this->writeRelease($stage . '/knossos-2.0.0', '2.0.0');
        $build($stage . '/knossos-2.0.0');
        exec(sprintf('tar -czf %s -C %s knossos-2.0.0', escapeshellarg($stage . '.tar.gz'), escapeshellarg($stage)));

        return (new VersionSwitch($data))->unpack($stage . '.tar.gz', '2.0.0');
    }

    /** @return iterable<string, array{callable(string): void, string}> */
    public static function unsafeReleases(): iterable
    {
        yield 'a link to an absolute path' => [static function (string $r): void {
            symlink('/etc', $r . '/evil');
        }, 'evil -> /etc'];
        yield 'a link that climbs out of the release' => [static function (string $r): void {
            symlink('../../outside', $r . '/evil');
        }, 'evil -> ../../outside'];
        yield 'a deep link that climbs past the root' => [static function (string $r): void {
            mkdir($r . '/a/b', 0o755, true);
            symlink('../../../x', $r . '/a/b/evil');
        }, 'a/b/evil'];
        yield 'a link that re-enters by the release name after it is renamed' => [static function (string $r): void {
            symlink('../knossos-2.0.0/x', $r . '/evil');
        }, 'evil -> ../knossos-2.0.0/x'];
        yield 'a member whose name contains an arrow' => [static function (string $r): void {
            symlink('/etc', $r . '/x -> y');
        }, 'x -> y -> /etc'];
        yield 'a link to the parent of the release' => [static function (string $r): void {
            symlink('..', $r . '/up');
        }, 'up -> ..'];
        yield 'a chain of links that adds up to an escape' => [static function (string $r): void {
            mkdir($r . '/d1/d2/d3', 0o755, true);
            symlink('../../..', $r . '/d1/d2/d3/S');
            symlink('S/../../../../etc', $r . '/d1/d2/d3/B');
        }, 'd1/d2/d3/B -> S/../../../../etc'];
        yield 'a dangling link whose remainder climbs out once the missing directory exists' => [static function (string $r): void {
            symlink('nothere/../../etc', $r . '/g');
        }, 'g -> nothere/../../etc'];
        yield 'a dangling link followed by a climb' => [static function (string $r): void {
            symlink('missing', $r . '/D');
            symlink('D/../../etc', $r . '/g');
        }, 'g -> D/../../etc'];
        yield 'a link that points at itself' => [static function (string $r): void {
            symlink('self', $r . '/self');
        }, 'self -> self'];
        yield 'two links that point at each other' => [static function (string $r): void {
            symlink('q', $r . '/p');
            symlink('p', $r . '/q');
        }, 'p -> q'];
        yield 'a member name that is not valid UTF-8' => [static function (string $r): void {
            symlink('/etc', $r . "/bad\xff\xfe");
        }, '/etc'];
        yield 'a fifo' => [static function (string $r): void {
            exec('mkfifo ' . escapeshellarg($r . '/pipe'));
        }, 'pipe'];
    }

    #[Group('install')]
    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeReleases')]
    public function testAnUnsafeMemberIsRefusedAndNothingIsLeftBehind(callable $build, string $named): void
    {
        $data = $this->installed('d', '1.0.0');

        $error = self::captureThrown(fn() => $this->unpackReleaseWith($build, $data), \Knossos\Install\InstallException::class);

        self::assertStringContainsString($named, $error->getMessage());
        // The message goes into --json output: it must encode, whatever bytes the member name held.
        self::assertNotFalse(json_encode($error->getMessage()));
        assertSame([], glob($data . '/versions/*.partial.*'));
        assertSame('1.0.0', (new VersionSwitch($data))->current());
    }

    #[Group('install')]
    public function testADirectoryTheArchiveMadeUnreadableIsRefusedAndStillRemoved(): void
    {
        $data = $this->installed('d', '1.0.0');
        $stage = $this->work . '/hide';
        $this->writeRelease($stage . '/knossos-2.0.0', '2.0.0');
        // tar cannot read a directory it must store as mode 0111, so the archive is written by python's tarfile.
        $script = <<<'PY'
import sys, tarfile
src, out = sys.argv[1], sys.argv[2]
with tarfile.open(out, "w:gz") as t:
    t.add(src, arcname="knossos-2.0.0")
    d = tarfile.TarInfo("knossos-2.0.0/hid"); d.type = tarfile.DIRTYPE; d.mode = 0o111; t.addfile(d)
    l = tarfile.TarInfo("knossos-2.0.0/hid/evil"); l.type = tarfile.SYMTYPE; l.linkname = "/etc"; l.mode = 0o777; t.addfile(l)
PY;
        exec(sprintf('python3 -I -c %s %s %s', escapeshellarg($script), escapeshellarg($stage . '/knossos-2.0.0'), escapeshellarg($stage . '.tar.gz')));

        $error = self::captureThrown(fn() => (new VersionSwitch($data))->unpack($stage . '.tar.gz', '2.0.0'), \Knossos\Install\InstallException::class);

        // Permissions do not bind root, which reads the directory and finds the link instead: refused either way.
        self::assertStringContainsString(posix_geteuid() === 0 ? 'hid/evil -> /etc' : 'Unreadable directory in the archive: hid', $error->getMessage());
        assertSame([], glob($data . '/versions/*.partial.*'));
        assertSame('1.0.0', (new VersionSwitch($data))->current());
    }

    #[Group('install')]
    public function testLinksThatStayInsideTheReleaseAreAccepted(): void
    {
        $data = $this->installed('d', '1.0.0');

        $partial = $this->unpackReleaseWith(static function (string $r): void {
            symlink('bin/knossos', $r . '/alias');
            mkdir($r . '/node_modules/.bin', 0o755, true);
            mkdir($r . '/node_modules/pkg', 0o755, true);
            file_put_contents($r . '/node_modules/pkg/tool', 'x');
            // The way real releases link their binaries: up and into a sibling directory.
            symlink('../pkg/tool', $r . '/node_modules/.bin/tool');
            symlink('../../bin/knossos', $r . '/node_modules/.bin/root');
            symlink('node_modules/../bin', $r . '/via-dots');
            // Dangling, but its longest existing prefix is inside the release.
            symlink('../does-not-exist', $r . '/node_modules/pkg/missing');
            // Dangling, and the rest of the target also stays inside.
            symlink('nothere/../sibling', $r . '/h');
            symlink('missing', $r . '/D');
            symlink('D/../sibling', $r . '/k');
        }, $data);

        assertSame(true, is_link($partial . '/node_modules/.bin/tool'));
        assertSame(true, is_link($partial . '/alias'));
    }

    #[Group('install')]
    public function testATarFailureDoesNotTalkAboutGit(): void
    {
        file_put_contents($this->work . '/bad.tar.gz', 'not an archive');
        $data = $this->installed('d', '1.0.0');

        $error = self::captureThrown(fn() => (new VersionSwitch($data))->unpack($this->work . '/bad.tar.gz', '2.0.0'), \Knossos\Install\InstallException::class);

        assertSame(false, str_contains($error->getMessage(), 'Git'));
        assertSame(false, str_contains($error->getMessage(), 'tar: tar:'));
        assertSame(true, str_contains($error->getMessage(), 'not a readable archive: tar: '));
    }

    #[Group('install')]
    public function testATreeDeeperThanPathMaxIsRemovedAndTheWorkingDirectoryIsKept(): void
    {
        $data = $this->installed('d', '1.0.0');
        $partial = $data . '/versions/2.0.0.partial.2147483646';
        mkdir($partial);
        $origin = (string) getcwd();
        chdir($partial);
        try {
            for ($level = 0; $level < 20; ++$level) {
                mkdir(str_repeat('a', 240));
                chdir(str_repeat('a', 240));
            }
            file_put_contents('leaf', 'x');
            symlink('/etc', 'out');
        } finally {
            chdir($origin);
        }

        (new VersionSwitch($data))->prune(['1.0.0']);

        assertSame(false, file_exists($partial));
        assertSame($origin, getcwd());
        assertSame(true, is_dir('/etc'));
        assertSame(['1.0.0'], array_map('basename', glob($data . '/versions/*')));
    }

    #[Group('install')]
    public function testARefusedOverLongReleaseLeavesNoPartialBehind(): void
    {
        $data = $this->installed('d', '1.0.0');
        $stage = $this->work . '/long';
        $this->writeRelease($stage . '/knossos-2.0.0', '2.0.0');
        $script = <<<'PY'
import sys, tarfile
src, out = sys.argv[1], sys.argv[2]
with tarfile.open(out, "w:gz", format=tarfile.PAX_FORMAT) as t:
    t.add(src, arcname="knossos-2.0.0")
    path = "knossos-2.0.0"
    for i in range(17):
        path += "/" + "b" * 250
        d = tarfile.TarInfo(path); d.type = tarfile.DIRTYPE; d.mode = 0o755; t.addfile(d)
PY;
        exec(sprintf('python3 -I -c %s %s %s', escapeshellarg($script), escapeshellarg($stage . '/knossos-2.0.0'), escapeshellarg($stage . '.tar.gz')));
        $origin = getcwd();

        $this->refused(fn() => (new VersionSwitch($data))->unpack($stage . '.tar.gz', '2.0.0'), 'Path too long in the archive');

        assertSame([], glob($data . '/versions/*.partial*'));
        assertSame($origin, getcwd());
    }
}
