<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Install;

use Knossos\Install\GitHubReleaseSource;
use Knossos\Install\Installation;
use Knossos\Install\InstallException;
use Knossos\Install\Launcher;
use Knossos\Install\ProcessReleaseProbe;
use Knossos\Install\SelfUpdater;
use Knossos\Install\UpdateCheck;
use Knossos\Install\VersionSwitch;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/** The ways the install code fails on disk, each with the state it must leave behind. No network. */
final class InstallFailurePathsTest extends KnossosTestCase
{
    use InstallFixtures;

    #[Group('install')]
    public function testALauncherIsNotWrittenWhereItsDirectoryCannotBeMadeAndIsRestoredOrRemoved(): void
    {
        $data = $this->installed('d', '1.0.0');
        file_put_contents($this->work . '/file', 'x');
        $template = $data . '/versions/1.0.0/launcher/knossos';

        $this->refused(fn() => Launcher::write($template, $data, $this->work . '/file/bin/knossos'), 'Could not create ' . $this->work . '/file/bin');
        $this->refused(fn() => Launcher::write($this->work . '/none', $data, $this->work . '/bin/knossos'), 'has no launcher template');

        mkdir($this->work . '/bin');
        file_put_contents($this->work . '/bin/knossos', 'new');
        Launcher::restore($this->work . '/bin/knossos', null);
        assertSame(false, file_exists($this->work . '/bin/knossos'));
        Launcher::restore($this->work . '/bin/knossos', "old\n");
        assertSame("old\n", Launcher::read($this->work . '/bin/knossos'));
        assertSame(null, Launcher::read($this->work . '/bin/none'));
        // A non-empty directory where the launcher goes: no rename lands, for root either.
        mkdir($this->work . '/bin/blocked/x', 0o755, true);
        $this->refused(fn() => Launcher::restore($this->work . '/bin/blocked', "old\n"), 'Could not restore the launcher');
        assertSame([], glob($this->work . '/bin/.knossos.tmp.*'));
    }

    #[Group('install')]
    public function testALauncherThatCannotBePutBackIsAddedToTheMessageNotPutInItsPlace(): void
    {
        $data = $this->installed('d', '1.0.0');
        mkdir($data . '/plugin');
        mkdir($this->work . '/d-bin');
        file_put_contents($this->work . '/d-bin/knossos', "old launcher\n");
        $this->publish($this->work . '/r', '2.0.0');
        $launcher = $this->work . '/d-bin/knossos';
        // The plugin step fails after the launcher was written, and the launcher's place is then blocked.
        $probe = new FakeProbe(pluginFailure: 'disk full', onPlugin: static function () use ($launcher): void {
            unlink($launcher);
            mkdir($launcher . '/x', 0o755, true);
        });

        $error = self::captureThrown(fn() => $this->updater($data, $probe)->update(null), InstallException::class);

        assertSame(sprintf('Release 2.0.0 could not write its plugin: disk full; 1.0.0 is current again, but the launcher %s could not be put back: run the installer again', $launcher), $error->getMessage());
        assertSame('1.0.0', (new VersionSwitch($data))->current());
    }

    #[Group('install')]
    public function testACheckThatCannotBeCachedIsSilentAndLeavesNoTemporaryFile(): void
    {
        mkdir($this->work . '/c/update-check.json/x', 0o755, true);
        $check = new UpdateCheck($this->work . '/c');

        $check->record('1.0.0');

        assertSame(null, $check->read());
        assertSame([], glob($this->work . '/c/update-check.json.tmp.*'));
    }

    #[Group('install')]
    public function testAReleaseThatOutlivesItsDeadlineIsStoppedAndCountsAsAFailure(): void
    {
        mkdir($this->work . '/slow/bin', 0o755, true);
        file_put_contents($this->work . '/slow/bin/knossos', "#!/bin/sh\nexec sleep 30\n");
        chmod($this->work . '/slow/bin/knossos', 0o755);
        $started = time();

        assertSame('doctor did not finish', (new ProcessReleaseProbe(1))->doctor($this->work . '/slow', $this->work . '/scratch'));
        assertSame(true, time() - $started < 10);
    }

    #[Group('install')]
    public function testUnpackingFailsCleanlyWhereNothingCanBeWritten(): void
    {
        $asset = $this->publish($this->work . '/r', '2.0.0');
        $archive = $this->work . '/r/' . $asset;

        // versions/ is a file: it cannot be created.
        mkdir($this->work . '/a');
        file_put_contents($this->work . '/a/versions', 'x');
        $this->refused(fn() => (new VersionSwitch($this->work . '/a'))->unpack($archive, '2.0.0'), 'Could not create ' . $this->work . '/a/versions');

        // A listing that passes and an extraction that fails: a file, then a directory of the same name.
        $script = <<<'PY'
import io, sys, tarfile
with tarfile.open(sys.argv[1], "w:gz") as t:
    for name, kind in (("knossos-2.0.0", tarfile.DIRTYPE), ("knossos-2.0.0/x", tarfile.REGTYPE), ("knossos-2.0.0/x/y", tarfile.REGTYPE)):
        info = tarfile.TarInfo(name); info.type = kind; info.mode = 0o755
        t.addfile(info, io.BytesIO(b"") if kind == tarfile.REGTYPE else None)
PY;
        exec(sprintf('python3 -I -c %s %s', escapeshellarg($script), escapeshellarg($this->work . '/clash.tar.gz')));
        $data = $this->installed('d', '1.0.0');
        $this->refused(fn() => (new VersionSwitch($data))->unpack($this->work . '/clash.tar.gz', '2.0.0'), 'Could not unpack the archive: tar: ');
        assertSame([], glob($data . '/versions/*.partial*'));

        // versions/ without write permission: the partial cannot be made (root writes anyway, and then it unpacks).
        chmod($data . '/versions', 0o555);
        $error = null;
        try {
            (new VersionSwitch($data))->unpack($archive, '2.0.0');
        } catch (InstallException $caught) {
            $error = $caught;
        } finally {
            chmod($data . '/versions', 0o755);
        }
        if (posix_geteuid() === 0) {
            self::assertNull($error);
        } else {
            self::assertInstanceOf(InstallException::class, $error);
            assertSame(true, str_starts_with($error->getMessage(), 'Could not create ' . $data . '/versions/2.0.0.partial.'));
        }
    }

    #[Group('install')]
    public function testSwitchingAndAdoptingFailCleanly(): void
    {
        $data = $this->installed('d', '1.0.0', '2.0.0');
        $switch = new VersionSwitch($data);

        $this->refused(fn() => $switch->adopt($this->work . '/no-such-partial', '3.0.0'), 'Could not move the unpacked release to ' . $data . '/versions/3.0.0');
        $this->refused(fn() => $switch->switchTo('2.0.0', '1.0.0', "/bin\xff"), 'cannot be recorded in install.json');
        assertSame('1.0.0', $switch->current());

        // `current` replaced by a non-empty directory: the rename of the new link cannot land.
        unlink($data . '/current');
        mkdir($data . '/current/x', 0o755, true);
        $this->refused(fn() => $switch->switchTo('2.0.0', '1.0.0', '/bin'), 'Could not switch ' . $data . '/current');
        assertSame([], glob($data . '/current.tmp.*'));
    }

    #[Group('install')]
    public function testLinksTheWalkHasToFollowStepByStep(): void
    {
        $data = $this->installed('d', '1.0.0');
        $stage = $this->work . '/links';
        $this->writeRelease($stage . '/knossos-2.0.0', '2.0.0');
        // `.` and empty segments are skipped, and a missing directory is counted through.
        symlink('./bin//knossos', $stage . '/knossos-2.0.0/dot');
        symlink('nothere/a/b/../../../bin', $stage . '/knossos-2.0.0/deep');
        exec(sprintf('tar -czf %s -C %s knossos-2.0.0', escapeshellarg($this->work . '/ok.tar.gz'), escapeshellarg($stage)));
        $partial = (new VersionSwitch($data))->unpack($this->work . '/ok.tar.gz', '2.0.0');
        assertSame(true, is_link($partial . '/dot') && is_link($partial . '/deep'));
        (new VersionSwitch($data))->discard($partial);

        // A link through another link that leads out: refused at the step that leaves.
        symlink('z/passwd', $stage . '/knossos-2.0.0/a');
        symlink('/etc', $stage . '/knossos-2.0.0/z');
        exec(sprintf('tar -czf %s -C %s knossos-2.0.0', escapeshellarg($this->work . '/out.tar.gz'), escapeshellarg($stage)));
        $this->refused(fn() => (new VersionSwitch($data))->unpack($this->work . '/out.tar.gz', '2.0.0'), 'Unsafe link in the archive: a -> z/passwd');
        assertSame([], glob($data . '/versions/*.partial*'));
    }

    #[Group('install')]
    public function testRemovingFromInsideEmptiesADirectoryButNeverAFileOrWhatALinkLeadsTo(): void
    {
        file_put_contents($this->work . '/plain', 'x');
        mkdir($this->work . '/outside');
        file_put_contents($this->work . '/outside/keep', 'x');
        $tree = $this->work . '/tree';
        mkdir($tree . '/a/b', 0o755, true);
        file_put_contents($tree . '/a/b/leaf', 'x');
        file_put_contents($tree . '/top', 'x');
        chmod($tree . '/a/b', 0o500);
        symlink($this->work . '/outside', $tree . '/a/out');
        symlink($this->work . '/outside', $this->work . '/link-to-outside');
        $origin = (string) getcwd();
        $method = new \ReflectionMethod(VersionSwitch::class, 'removeFromInside');
        $switch = new VersionSwitch($this->work);

        // A file and a link are not entered: both stay, and so does what the link leads to.
        $method->invoke($switch, $this->work . '/plain');
        $method->invoke($switch, $this->work . '/link-to-outside');
        assertSame(true, is_file($this->work . '/plain'));
        assertSame(true, is_link($this->work . '/link-to-outside'));

        // A directory goes, every level of it, the read-only one included, and the link inside is removed, not followed.
        $method->invoke($switch, $tree);
        assertSame(false, file_exists($tree));
        assertSame(true, is_file($this->work . '/outside/keep'));
        assertSame($origin, getcwd());
    }

    #[Group('install')]
    public function testEveryRequestSendsAFixedUserAgentAndTheAcceptHeaderAndNothingElse(): void
    {
        // The API's `latest` answer and every asset are fetched with these.
        assertSame(['http' => [
            'method' => 'GET',
            'timeout' => 10,
            'follow_location' => 1,
            'max_redirects' => 5,
            'ignore_errors' => false,
            'header' => "User-Agent: knossos-self-update\r\nAccept: application/vnd.github+json, application/octet-stream\r\n",
        ]], GitHubReleaseSource::requestOptions('download'));
        // A mirror's `/latest` is followed with these: no body, only the User-Agent.
        assertSame(['http' => [
            'method' => 'HEAD',
            'timeout' => 10,
            'follow_location' => 1,
            'max_redirects' => 5,
            'ignore_errors' => false,
            'header' => "User-Agent: knossos-self-update\r\n",
        ]], GitHubReleaseSource::requestOptions('redirect'));

        // Nothing depends on who runs it: the same options under another user's environment.
        $expected = [GitHubReleaseSource::requestOptions('download'), GitHubReleaseSource::requestOptions('redirect')];
        $before = [getenv('HOME'), getenv('USER')];
        putenv('HOME=/home/someone-else');
        putenv('USER=someone-else');
        try {
            assertSame($expected, [GitHubReleaseSource::requestOptions('download'), GitHubReleaseSource::requestOptions('redirect')]);
        } finally {
            putenv($before[0] === false ? 'HOME' : 'HOME=' . $before[0]);
            putenv($before[1] === false ? 'USER' : 'USER=' . $before[1]);
        }
    }

    #[Group('install')]
    public function testEveryTransferIsBoundedInSizeAndTime(): void
    {
        assertSame(1_048_576, GitHubReleaseSource::limitFor(null));
        assertSame(536_870_912, GitHubReleaseSource::limitFor($this->work . '/asset'));
        assertSame(600, GitHubReleaseSource::MAX_SECONDS);

        file_put_contents($this->work . '/body', 'payload');
        $stream = fopen($this->work . '/body', 'rb');
        self::assertNotFalse($stream);
        assertSame('payload', GitHubReleaseSource::copy($stream, null, 'u', 7, time() + 60));
        rewind($stream);
        $this->refused(static fn() => GitHubReleaseSource::copy($stream, null, 'u', 6, time() + 60), 'Could not download u completely');
        rewind($stream);
        $this->refused(static fn() => GitHubReleaseSource::copy($stream, null, 'u', 100, time() - 1), 'Could not download u completely');
        rewind($stream);
        assertSame('', GitHubReleaseSource::copy($stream, $this->work . '/copy', 'u', 100, time() + 60));
        assertSame('payload', file_get_contents($this->work . '/copy'));
        rewind($stream);
        $this->refused(fn() => GitHubReleaseSource::copy($stream, $this->work . '/no/such/dir/copy', 'u', 100, time() + 60), 'Could not write');
        fclose($stream);
    }

    #[Group('install')]
    public function testARedirectIsFollowedToItsLastLocation(): void
    {
        $url = 'https://example.test:8443/r/latest';
        assertSame($url, GitHubReleaseSource::locationOf([], $url));
        assertSame($url, GitHubReleaseSource::locationOf(['Location' => ''], $url));
        assertSame('https://b.test/tag/v2', GitHubReleaseSource::locationOf(['Location' => ['https://a.test/x', 'https://b.test/tag/v2']], $url));
        assertSame('https://example.test:8443/r/tag/v1', GitHubReleaseSource::locationOf(['location' => '/r/tag/v1'], $url));
        assertSame('https://h.test/tag/v1', GitHubReleaseSource::locationOf(['Location' => '/tag/v1'], 'https://h.test/latest'));
    }

    /** The two calls that touch a stream, run on what needs no network: a local file, and something unreachable. */
    #[Group('install')]
    public function testTheStreamCallsWorkOnALocalFileAndRefuseWhatTheyCannotReach(): void
    {
        $finalUrl = new \ReflectionMethod(GitHubReleaseSource::class, 'finalUrl');
        $this->refused(fn() => $finalUrl->invoke(null, $this->work . '/nothing-here'), 'Could not reach');

        $http = new \ReflectionMethod(GitHubReleaseSource::class, 'http');
        file_put_contents($this->work . '/body', 'payload');
        assertSame('payload', $http->invoke(null, $this->work . '/body', null));
        assertSame('', $http->invoke(null, $this->work . '/body', $this->work . '/copy'));
        assertSame('payload', file_get_contents($this->work . '/copy'));
        $this->refused(fn() => $http->invoke(null, $this->work . '/missing', null), 'Could not download');
    }

    private function updater(string $data, FakeProbe $probe): SelfUpdater
    {
        return new SelfUpdater(new Installation($data . '/current'), new GitHubReleaseSource($this->work . '/r', null, 'linux-x86_64'), $probe, 'linux-x86_64');
    }
}
