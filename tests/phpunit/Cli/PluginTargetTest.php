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

    /** An installed release that runs the command by hand gets the stable plugin directory and the launcher, and with --no-register no claude command. */
    #[Group('cli')]
    public function testAnInstalledReleaseMaterialisesItsPluginOutsideVersions(): void
    {
        $data = $this->installed('d', '1.0.0');
        $root = $data . '/versions/1.0.0';
        exec(sprintf(
            'tar -C %s --exclude=node_modules -cf - hooks .claude-plugin skills types | tar -C %s -xf -',
            escapeshellarg(self::repositoryRoot()),
            escapeshellarg($root),
        ));
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
}
