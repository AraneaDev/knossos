<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Cli;

use Knossos\Cli\CliCommandContext;
use Knossos\Cli\CliHelpRenderer;
use Knossos\Cli\CliInputLoader;
use Knossos\Cli\CliOptionParser;
use Knossos\Cli\Command\CliCommandSet;
use Knossos\Cli\Command\TuiCommand;
use Knossos\Runtime\RuntimeFactory;
use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/** `tui`, what bare `knossos` runs: the pane in this terminal, which Node draws; the command checks for a terminal and for Node and hands over. */
final class TuiCommandTest extends KnossosTestCase
{
    #[Group('cli')]
    public function testItIsOneOfTheCommandsAndTakesAnEditorAndATheme(): void
    {
        $handlers = array_filter(CliCommandSet::all(new CliHelpRenderer(), 'test'), static fn($c): bool => $c->supports('tui'));
        assertSame(1, count($handlers));
        assertSame(['editor', 'theme'], (new TuiCommand())->allowedOptions('tui'));
    }

    #[Group('cli')]
    public function testNotATerminalPrintsUsageAndFails(): void
    {
        [$status, $error, $calls] = $this->runWith('v24.3.0', terminal: false);

        assertSame(2, $status);
        assertContains('  knossos usage', explode("\n", $error));
        assertContains('knossos opens the pane in this terminal; knossos usage prints this list.', explode("\n", $error));
        assertContains('  knossos web [path] [--port=N] [--no-open] [--editor=<command>]', explode("\n", $error));
        assertSame([], $calls);
    }

    #[Group('cli')]
    public function testAValueAfterASpaceIsRefusedWithTheFormThatWorks(): void
    {
        foreach (['theme' => '--theme needs its value after an equals sign: --theme=dark|light', 'editor' => '--editor needs its value after an equals sign: --editor=<command>'] as $name => $message) {
            $error = captureThrows(fn() => $this->runWith('v24.3.0', options: [$name => ['']]), \InvalidArgumentException::class);
            assertSame($message, $error->getMessage());
        }
    }

    #[Group('cli')]
    public function testRefusesOldNode(): void
    {
        [$status, $error] = $this->runWith('v22.0.0');

        assertSame(2, $status);
        assertSame("knossos needs Node 24 or newer (found v22.0.0).\n", $error);
    }

    #[Group('cli')]
    public function testNode24RunsTheTuiEntryForTheDirectoryWithItsOptions(): void
    {
        [$status, $error, $calls] = $this->runWith('v24.3.0', exit: 5, positionals: ['/some/project'], options: ['editor' => ['zed'], 'theme' => ['light']]);

        assertSame(5, $status);
        assertSame('', $error);
        assertSame(['--version', self::repositoryRoot() . '/tui/main.ts /some/project --editor=zed --theme=light'], $calls);
    }

    #[Group('cli')]
    public function testTheDirectoryDefaultsToTheWorkingOne(): void
    {
        [$status, , $calls] = $this->runWith('v25.0.0');

        assertSame(0, $status);
        assertSame(self::repositoryRoot() . '/tui/main.ts ' . getcwd(), $calls[1]);
    }

    /**
     * Runs `tui` with a stub `node` alone on PATH that answers `--version`
     * with `$version`, notes every call's arguments, and exits `$exit` when run.
     *
     * @param list<string> $positionals
     * @param array<string, list<string>> $options
     * @return array{int, string, list<string>}
     */
    private function runWith(string $version, bool $terminal = true, int $exit = 0, array $positionals = [], array $options = []): array
    {
        $bin = sys_get_temp_dir() . '/knossos-stale-tui-' . bin2hex(random_bytes(4));
        mkdir($bin);
        $calls = $bin . '/calls';
        file_put_contents($bin . '/node', sprintf(
            "#!/bin/sh\necho \"\$*\" >> '%s'\n[ \"\$1\" = --version ] && { echo %s; exit 0; }\nexit %d\n",
            $calls,
            $version,
            $exit,
        ));
        chmod($bin . '/node', 0o755);
        $path = (string) getenv('PATH');
        putenv('PATH=' . $bin);
        $stderr = fopen('php://memory', 'w+');
        try {
            $context = new CliCommandContext(new CliOptionParser(), new CliInputLoader(), new RuntimeFactory(self::repositoryRoot()), ':memory:');
            $status = (new TuiCommand($stderr, static fn(): bool => $terminal))->run('tui', $positionals, $options, $context);
            rewind($stderr);
            $lines = is_file($calls) ? array_values(array_filter(explode("\n", (string) file_get_contents($calls)))) : [];

            return [$status, (string) stream_get_contents($stderr), $lines];
        } finally {
            putenv('PATH=' . $path);
            $this->removeTempTree($bin);
        }
    }
}
