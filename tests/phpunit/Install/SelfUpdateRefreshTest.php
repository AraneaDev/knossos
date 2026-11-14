<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Install;

use Knossos\Install\GitHubReleaseSource;
use Knossos\Install\Installation;
use Knossos\Install\InstallException;
use Knossos\Install\Launcher;
use Knossos\Install\SelfUpdater;
use Knossos\Install\VersionSwitch;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/** After a switch, self-update rewrites the launcher and the plugin from the release it switched to, or undoes it all. */
final class SelfUpdateRefreshTest extends KnossosTestCase
{
    use InstallFixtures;

    private const PLATFORM = 'linux-x86_64';

    #[Group('install')]
    public function testAnUpdateRewritesTheLauncherAndThePluginFromTheNewRelease(): void
    {
        $data = $this->installed('d', '1.0.0');
        mkdir($data . '/plugin');
        $this->publish($this->work . '/r', '2.0.0');
        $probe = new FakeProbe();

        $this->updater($data, $probe)->update(null);

        $launcher = $this->work . '/d-bin/knossos';
        assertSame(Launcher::render((string) file_get_contents($data . '/versions/2.0.0/launcher/knossos'), $data), file_get_contents($launcher));
        assertSame(true, str_contains((string) file_get_contents($launcher), "launcher of 2.0.0\nINSTALL_DIR='" . $data . "'\n"));
        assertSame(0o755, fileperms($launcher) & 0o777);
        assertSame([['2.0.0', $data . '/plugin', $launcher, $data]], $probe->plugins);
        assertSame("2.0.0\n", file_get_contents($data . '/plugin/from'));
        assertSame($this->work . '/d-bin', (new Installation($data . '/current'))->record()['bin_dir']);
    }

    #[Group('install')]
    public function testWithoutAPluginDirectoryNoPluginIsWritten(): void
    {
        $data = $this->installed('d', '1.0.0');
        $this->publish($this->work . '/r', '2.0.0');
        $probe = new FakeProbe();

        $this->updater($data, $probe)->update(null);

        assertSame([], $probe->plugins);
        assertSame(false, file_exists($data . '/plugin'));
    }

    #[Group('install')]
    public function testAPluginThatCannotBeWrittenRollsTheWholeUpdateBack(): void
    {
        $data = $this->installed('d', '1.0.0');
        mkdir($data . '/plugin');
        mkdir($this->work . '/d-bin');
        file_put_contents($this->work . '/d-bin/knossos', "old launcher\n");
        $installJson = (string) file_get_contents($data . '/install.json');
        $this->publish($this->work . '/r', '2.0.0');

        $error = self::captureThrown(fn() => $this->updater($data, new FakeProbe(pluginFailure: 'disk full'))->update(null), InstallException::class);
        assertSame('Release 2.0.0 could not write its plugin: disk full; 1.0.0 is current again', $error->getMessage());

        assertSame('1.0.0', (new VersionSwitch($data))->current());
        assertSame($installJson, file_get_contents($data . '/install.json'));
        assertSame("old launcher\n", file_get_contents($this->work . '/d-bin/knossos'));
        assertSame(false, is_file($data . '/previous-version'));
        assertSame([], glob($this->work . '/d-bin/.knossos.tmp.*'));
    }

    #[Group('install')]
    public function testAReleaseWithoutALauncherTemplateIsRolledBackAndNoLauncherIsLeft(): void
    {
        $data = $this->installed('d', '1.0.0');
        $stage = $this->work . '/stage';
        $this->writeRelease($stage . '/knossos-2.0.0', '2.0.0');
        unlink($stage . '/knossos-2.0.0/launcher/knossos');
        mkdir($this->work . '/r');
        exec(sprintf('tar -czf %s -C %s knossos-2.0.0', escapeshellarg($this->work . '/r/knossos-2.0.0-linux-x86_64.tar.gz'), escapeshellarg($stage)));
        file_put_contents($this->work . '/r/SHA256SUMS', hash_file('sha256', $this->work . '/r/knossos-2.0.0-linux-x86_64.tar.gz') . "  knossos-2.0.0-linux-x86_64.tar.gz\n");

        $this->refused(fn() => $this->updater($data)->update(null), 'has no launcher template');

        assertSame('1.0.0', (new VersionSwitch($data))->current());
        assertSame(false, file_exists($this->work . '/d-bin/knossos'));
    }

    #[Group('install')]
    public function testARollbackRewritesBothFromTheVersionItReturnsTo(): void
    {
        $data = $this->installed('d', '1.0.0');
        mkdir($data . '/plugin');
        $this->publish($this->work . '/r', '2.0.0');
        $probe = new FakeProbe();
        $updater = $this->updater($data, $probe);
        $updater->update(null);

        $updater->rollback();

        assertSame("1.0.0\n", file_get_contents($data . '/plugin/from'));
        assertSame('1.0.0', $probe->plugins[1][0]);
        assertSame(true, str_contains((string) file_get_contents($this->work . '/d-bin/knossos'), 'launcher of 1.0.0'));
    }

    #[Group('install')]
    public function testARecordWithoutABinDirectoryIsRefusedAndNothingChanges(): void
    {
        $data = $this->installed('d', '1.0.0');
        $this->publish($this->work . '/r', '2.0.0');
        file_put_contents($data . '/install.json', '{"mode": "native", "version": "1.0.0"}');
        $installation = new Installation($data . '/current');
        $message = 'The install record ' . $data . '/install.json does not name where the launcher is; run the installer again.';

        foreach ([static fn() => $installation->binDir(), static fn() => $installation->launcher()] as $call) {
            assertSame($message, self::captureThrown($call, InstallException::class)->getMessage());
        }
        $probe = new FakeProbe();
        assertSame($message, self::captureThrown(fn() => $this->updater($data, $probe)->update(null), InstallException::class)->getMessage());
        assertSame($message, self::captureThrown(fn() => $this->updater($data, $probe)->rollback(), InstallException::class)->getMessage());

        assertSame('1.0.0', (new VersionSwitch($data))->current());
        assertSame(['1.0.0'], array_map('basename', glob($data . '/versions/*')));
        assertSame(0, $probe->doctorRuns);
        assertSame(false, file_exists($this->work . '/home/.local'));
        assertSame('{"mode": "native", "version": "1.0.0"}', file_get_contents($data . '/install.json'));
    }

    #[Group('install')]
    public function testALauncherThatCannotBeWrittenIsNamedOnceAndThePreviousReleaseIsCurrentAgain(): void
    {
        $data = $this->installed('d', '1.0.0');
        mkdir($data . '/plugin');
        mkdir($this->work . '/d-bin');
        file_put_contents($this->work . '/d-bin/knossos', "old launcher\n");
        chmod($this->work . '/d-bin', 0o555);
        $this->publish($this->work . '/r', '2.0.0');
        $probe = new FakeProbe();
        try {
            if (@file_put_contents($this->work . '/d-bin/probe', 'x') !== false) {
                // Root writes anyway: stand in with a bin directory whose launcher path is a directory.
                chmod($this->work . '/d-bin', 0o755);
                unlink($this->work . '/d-bin/probe');
                unlink($this->work . '/d-bin/knossos');
                mkdir($this->work . '/d-bin/knossos');
            }
            $error = self::captureThrown(fn() => $this->updater($data, $probe)->update(null), InstallException::class);
        } finally {
            chmod($this->work . '/d-bin', 0o755);
        }

        assertSame('Could not write the launcher ' . $this->work . '/d-bin/knossos; 1.0.0 is current again', $error->getMessage());
        assertSame('1.0.0', (new VersionSwitch($data))->current());
        assertSame([], $probe->plugins);
    }

    #[Group('install')]
    public function testAHandSetGraphDirectorySurvivesAnUpdateAndARollback(): void
    {
        $data = $this->installed('d', '1.0.0');
        mkdir($data . '/plugin/hooks/scripts', 0o755, true);
        file_put_contents($data . '/plugin/hooks/scripts/lib.sh', "KNOSSOS_INSTALLED_DATA_DIR='/srv/my graph'\n");
        $this->publish($this->work . '/r', '2.0.0');
        $probe = new FakeProbe();
        $updater = $this->updater($data, $probe);

        $updater->update(null);
        $updater->rollback();

        assertSame(['/srv/my graph', '/srv/my graph'], array_column($probe->plugins, 3));
        // A plugin without a baked directory gets the install directory.
        unlink($data . '/plugin/hooks/scripts/lib.sh');
        $updater->rollback();
        assertSame($data, $probe->plugins[2][3]);
    }

    #[Group('install')]
    public function testTheLauncherIsRenderedTheWayTheInstallerRendersIt(): void
    {
        $template = "#!/bin/sh\nINSTALL_DIR='__KNOSSOS_INSTALL_DIR__'\nexec x";

        assertSame("#!/bin/sh\nINSTALL_DIR='/it'\\''s here'\nexec x\n", Launcher::render($template, "/it's here"));
        $this->refused(static fn() => Launcher::render("#!/bin/sh\n", '/x'), 'no install directory line');
        $this->refused(static fn() => Launcher::render($template, "/x\ny"), 'newline');
    }

    private function updater(string $data, ?FakeProbe $probe = null): SelfUpdater
    {
        return new SelfUpdater(
            new Installation($data . '/current'),
            new GitHubReleaseSource($this->work . '/r', null, self::PLATFORM),
            $probe ?? new FakeProbe(),
            self::PLATFORM,
        );
    }
}
