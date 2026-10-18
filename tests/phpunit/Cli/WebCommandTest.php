<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Cli;

use Knossos\Cli\CliCommandContext;
use Knossos\Cli\CliInputLoader;
use Knossos\Cli\CliOptionParser;
use Knossos\Cli\Command\CliCommandSet;
use Knossos\Cli\Command\WebCommand;
use Knossos\Cli\CliHelpRenderer;
use Knossos\Runtime\RuntimeFactory;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;

/** `web`: the pane in a browser tab, which Node serves; the command checks Node and hands over to it. */
final class WebCommandTest extends KnossosTestCase
{
    #[Group('cli')]
    public function testItIsOneOfTheCommandsAndTakesAPortAndNoOpen(): void
    {
        $handlers = array_filter(CliCommandSet::all(new CliHelpRenderer(), 'test'), static fn($c): bool => $c->supports('web'));
        assertSame(1, count($handlers));
        assertSame(['port', 'no-open'], (new WebCommand())->allowedOptions('web'));
    }

    #[Group('cli')]
    public function testANodeOlderThan24IsRefusedWithTheVersionItFound(): void
    {
        [$status, $error, $calls] = $this->runWith('v22.11.0');

        assertSame(2, $status);
        assertSame("knossos web needs Node 24 or newer (found v22.11.0).\n", $error);
        assertSame(['--version'], $calls);
    }

    #[Group('cli')]
    public function testNoNodeOnThePathIsRefused(): void
    {
        [$status, $error] = $this->runWith(null);

        assertSame(2, $status);
        assertSame("knossos web needs Node 24 or newer (found none on PATH).\n", $error);
    }

    #[Group('cli')]
    public function testNode24RunsTheWebEntryForTheDirectoryWithItsOptionsAndReturnsItsExitCode(): void
    {
        [$status, $error, $calls] = $this->runWith('v24.3.0', exit: 5, positionals: ['/some/project'], options: ['port' => ['8123'], 'no-open' => ['true']]);

        assertSame(5, $status);
        assertSame('', $error);
        assertSame(['--version', self::repositoryRoot() . '/web/main.ts /some/project --port=8123 --no-open'], $calls);
    }

    #[Group('cli')]
    public function testTheDirectoryDefaultsToTheWorkingOne(): void
    {
        [$status, , $calls] = $this->runWith('v25.0.0');

        assertSame(0, $status);
        assertSame(self::repositoryRoot() . '/web/main.ts ' . getcwd(), $calls[1]);
        assertContains('--version', $calls);
        assertFalse(in_array('--no-open', explode(' ', $calls[1]), true));
    }

    /**
     * Runs `web` with a stub `node` alone on PATH that answers `--version`
     * with `$version` (none at all for null), notes every call's arguments,
     * and exits `$exit` when run.
     *
     * @param list<string> $positionals
     * @param array<string, list<string>> $options
     * @return array{int, string, list<string>}
     */
    private function runWith(?string $version, int $exit = 0, array $positionals = [], array $options = []): array
    {
        $bin = sys_get_temp_dir() . '/knossos-stale-web-' . bin2hex(random_bytes(4));
        mkdir($bin);
        $calls = $bin . '/calls';
        if ($version !== null) {
            file_put_contents($bin . '/node', sprintf(
                "#!/bin/sh\necho \"\$*\" >> '%s'\n[ \"\$1\" = --version ] && { echo %s; exit 0; }\nexit %d\n",
                $calls,
                $version,
                $exit,
            ));
            chmod($bin . '/node', 0o755);
        }
        $path = (string) getenv('PATH');
        putenv('PATH=' . $bin);
        $stderr = fopen('php://memory', 'w+');
        try {
            $context = new CliCommandContext(new CliOptionParser(), new CliInputLoader(), new RuntimeFactory(self::repositoryRoot()), ':memory:');
            $status = (new WebCommand($stderr))->run('web', $positionals, $options, $context);
            rewind($stderr);
            $lines = is_file($calls) ? array_values(array_filter(explode("\n", (string) file_get_contents($calls)))) : [];

            return [$status, (string) stream_get_contents($stderr), $lines];
        } finally {
            putenv('PATH=' . $path);
            $this->removeTempTree($bin);
        }
    }
}
