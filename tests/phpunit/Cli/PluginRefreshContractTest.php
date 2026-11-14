<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Cli;

use Knossos\Cli\Command\PluginCommand;
use Knossos\Install\ProcessReleaseProbe;
use Knossos\Tests\Phpunit\Install\InstallFixtures;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The command line self-update hands to another release's `install-agent-plugin`.
 *
 * A released version depends on this: its self-update runs the release it
 * updates to (and, on --rollback, an older one) with exactly the arguments of
 * {@see ProcessReleaseProbe::pluginArguments()}. Options may be added to
 * `install-agent-plugin`, but these may never be renamed or removed, and
 * `--no-register` must keep meaning "write the plugin, run no claude
 * command". If this test fails, the change breaks every update into it.
 */
final class PluginRefreshContractTest extends KnossosTestCase
{
    use InstallFixtures;

    #[Group('cli')]
    public function testTheRefreshCommandLineMaterialisesThePluginAndRunsNoClaudeCommand(): void
    {
        $arguments = ProcessReleaseProbe::pluginArguments($this->work . '/inst/plugin', $this->work . '/bin dir/knossos', $this->work . '/graph');
        assertSame(['install-agent-plugin', '--execute', '--no-register', '--plugin-dir=', '--bin=', '--data-dir='], array_map(static fn(string $a): string => (string) preg_replace('/=.*$/', '=', $a), $arguments));
        $allowed = (new PluginCommand())->allowedOptions('install-agent-plugin');
        foreach (['execute', 'no-register', 'plugin-dir', 'bin', 'data-dir'] as $option) {
            assertSame(true, in_array($option, $allowed, true), $option);
        }

        // A claude that would leave a mark, first on PATH, so a registration attempt is seen.
        mkdir($this->work . '/stub');
        file_put_contents($this->work . '/stub/claude', "#!/bin/sh\n: > \"\$CLAUDE_MARK\"\nexit 1\n");
        chmod($this->work . '/stub/claude', 0o755);
        $path = $this->work . '/stub:' . (string) getenv('PATH');
        $environment = ['PATH' => $path, 'HOME' => $this->work . '/home', 'CLAUDE_MARK' => $this->work . '/claude-ran', 'KNOSSOS_DATA_DIR' => $this->work . '/scratch'];
        exec(sprintf('env PATH=%s sh -c %s', escapeshellarg($path), escapeshellarg('command -v claude')), $found);
        assertSame([$this->work . '/stub/claude'], $found);

        $process = proc_open(['php', self::repositoryRoot() . '/bin/knossos', ...$arguments], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->work, $environment);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        $status = proc_close($process);

        assertSame(0, $status, (string) $output);
        assertSame(false, file_exists($this->work . '/claude-ran'));
        assertSame(true, is_file($this->work . '/inst/plugin/.claude-plugin/plugin.json'));
        $library = (string) file_get_contents($this->work . '/inst/plugin/hooks/scripts/lib.sh');
        assertSame(true, str_contains($library, "\nKNOSSOS_INSTALLED_BIN='" . $this->work . "/bin dir/knossos'\n"));
        assertSame(true, str_contains($library, "\nKNOSSOS_INSTALLED_DATA_DIR='" . $this->work . "/graph'\n"));
    }
}
