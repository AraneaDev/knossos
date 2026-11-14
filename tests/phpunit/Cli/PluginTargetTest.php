<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Cli;

use InvalidArgumentException;
use Knossos\Cli\CliCommandContext;
use Knossos\Cli\CliInputLoader;
use Knossos\Cli\CliOptionParser;
use Knossos\Cli\Command\PluginCommand;
use Knossos\Cli\Command\PluginTarget;
use Knossos\Runtime\RuntimeFactory;
use Knossos\Tests\Phpunit\Install\InstallFixtures;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Where `install-agent-plugin` writes the plugin and which binary its hooks call, in a checkout and in an installed release. */
final class PluginTargetTest extends KnossosTestCase
{
    use InstallFixtures;

    #[Group('cli')]
    public function testACheckoutKeepsItsOwnPluginDirectoryAndBinary(): void
    {
        $root = self::repositoryRoot();
        $target = PluginTarget::resolve($root, null, null);

        assertSame($root . '/.plugin', $target->directory);
        assertSame($root . '/bin/knossos', $target->bin);
        $chosen = PluginTarget::resolve($root, '/p/plugin', '/b/knossos');
        assertSame(['/p/plugin', '/b/knossos'], [$chosen->directory, $chosen->bin]);
    }

    #[Group('cli')]
    public function testAnInstalledReleaseUsesTheInstallDirectoryAndTheLauncher(): void
    {
        $data = $this->installed('d', '1.0.0');

        $target = PluginTarget::resolve($data . '/current', null, null);

        assertSame($data . '/plugin', $target->directory);
        assertSame($this->work . '/d-bin/knossos', $target->bin);
    }

    #[Group('cli')]
    public function testAnInstalledReleaseWhoseRecordNamesNoLauncherIsRefused(): void
    {
        $data = $this->installed('d', '1.0.0');
        file_put_contents($data . '/install.json', '{"mode": "native", "version": "1.0.0"}');

        $error = self::captureThrown(static fn() => PluginTarget::resolve($data . '/current', null, null), \Knossos\Install\InstallException::class);

        assertSame('The install record ' . $data . '/install.json does not name where the launcher is; run the installer again.', $error->getMessage());
        assertSame(false, file_exists($this->work . '/home/.local'));
    }

    #[Group('cli')]
    public function testNothingInsideVersionsAndNoRelativePathIsAccepted(): void
    {
        $data = $this->installed('d', '1.0.0');
        $root = $data . '/versions/1.0.0';

        foreach ([[$root . '/.plugin', null], [null, $root . '/bin/knossos'], [$data . '/current/.plugin', null], [$data . '/versions/2.0.0/plugin', null]] as [$directory, $bin]) {
            $error = self::captureThrown(static fn() => PluginTarget::resolve($root, $directory, $bin), InvalidArgumentException::class);
            assertSame(true, str_contains($error->getMessage(), 'must not be inside ' . $data . '/versions'), $error->getMessage());
        }
        $error = self::captureThrown(static fn() => PluginTarget::resolve($root, 'plugin', null), InvalidArgumentException::class);
        assertSame('plugin-dir must be an absolute path, got "plugin".', $error->getMessage());
        $error = self::captureThrown(static fn() => PluginTarget::resolve(self::repositoryRoot(), null, 'bin/knossos'), InvalidArgumentException::class);
        assertSame('bin must be an absolute path, got "bin/knossos".', $error->getMessage());
    }

    /**
     * Code under `<x>/versions/<v>` whose record is missing, unreadable or names another version: refused, nothing written, no claude.
     *
     * Without the refusal it would read as a checkout and materialise `versions/<v>/.plugin` baked to `versions/<v>/bin/knossos`.
     */
    #[Group('cli')]
    public function testCodeUnderVersionsThatItsRecordDoesNotNameInstallsNoPlugin(): void
    {
        mkdir($this->work . '/stub');
        file_put_contents($this->work . '/stub/claude', "#!/bin/sh\n: > " . escapeshellarg($this->work . '/claude-ran') . "\n");
        chmod($this->work . '/stub/claude', 0o755);
        $path = getenv('PATH');
        putenv('PATH=' . $this->work . '/stub:' . (string) $path);
        try {
            foreach ([null, 'not json', '{"mode": "native", "version": "0.9.0", "bin_dir": "/b"}'] as $index => $record) {
                $data = $this->work . '/inst' . $index;
                $root = $data . '/versions/1.0.0';
                mkdir($root, 0o755, true);
                $this->copyPluginSources($root);
                if ($record !== null) {
                    file_put_contents($data . '/install.json', $record);
                }
                $message = sprintf('This code sits in %1$s/versions, but %1$s/install.json does not record it as the installed release: run the installer again, or update a source checkout with git pull.', $data);
                $context = new CliCommandContext(new CliOptionParser(), new CliInputLoader(), new RuntimeFactory($root), ':memory:');

                foreach ([['execute' => ['true']], []] as $options) {
                    $error = self::captureThrown(static fn() => (new PluginCommand('1.0.0'))->run('install-agent-plugin', [], $options, $context), \Knossos\Install\InstallException::class);
                    assertSame($message, $error->getMessage());
                }
                assertSame(false, file_exists($root . '/.plugin'), (string) $record);
                assertSame(false, file_exists($data . '/plugin'), (string) $record);
            }
        } finally {
            putenv($path === false ? 'PATH' : 'PATH=' . $path);
        }
        assertSame(false, file_exists($this->work . '/claude-ran'));
    }

    /** An installed release that runs the command by hand gets the stable plugin directory and the launcher, and with --no-register no claude command. */
    #[Group('cli')]
    public function testAnInstalledReleaseMaterialisesItsPluginOutsideVersions(): void
    {
        $data = $this->installed('d', '1.0.0');
        $root = $data . '/versions/1.0.0';
        $this->copyPluginSources($root);
        $context = new CliCommandContext(new CliOptionParser(), new CliInputLoader(), new RuntimeFactory($data . '/current'), ':memory:');

        ob_start();
        try {
            (new PluginCommand('1.0.0'))->run('install-agent-plugin', [], ['execute' => ['true'], 'no-register' => ['true'], 'json' => ['true'], 'data-dir' => [$data]], $context);
        } finally {
            $output = (string) ob_get_clean();
        }

        $report = json_decode($output, true);
        self::assertIsArray($report);
        assertSame($data . '/plugin', $report['plugin_directory']);
        assertSame([], $report['commands']);
        $library = (string) file_get_contents($data . '/plugin/hooks/scripts/lib.sh');
        assertSame(true, str_contains($library, "KNOSSOS_INSTALLED_BIN='" . $this->work . "/d-bin/knossos'"));
        assertSame(true, str_contains($library, "KNOSSOS_INSTALLED_DATA_DIR='" . $data . "'"));
        assertSame(false, str_contains($library, '/versions/'));
        assertSame(false, file_exists($root . '/.plugin'));
    }

    /** The files a plugin is materialised from, copied from this checkout into a stand-in release. */
    private function copyPluginSources(string $root): void
    {
        exec(sprintf(
            'tar -C %s --exclude=node_modules -cf - hooks .claude-plugin skills types | tar -C %s -xf -',
            escapeshellarg(self::repositoryRoot()),
            escapeshellarg($root),
        ));
    }
}
