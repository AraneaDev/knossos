<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Cli;

use InvalidArgumentException;
use Knossos\Cli\CliCommandContext;
use Knossos\Cli\CliInputLoader;
use Knossos\Cli\CliOptionParser;
use Knossos\Cli\Command\AutostartCommand;
use Knossos\Runtime\RuntimeFactory;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/** What `autostart --json` reads, sets and reports, beside the roots file. */
final class AutostartCommandTest extends KnossosTestCase
{
    private string $tempDir;
    private string $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = (string) realpath(sys_get_temp_dir()) . '/knossos-autostart-cmd-' . bin2hex(random_bytes(4));
        $this->project = $this->tempDir . '/project';
        mkdir($this->project . '/.git', 0o755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tempDir));
        parent::tearDown();
    }

    #[Group('cli')]
    public function testItReportsOffThenSetsOnAndOff(): void
    {
        assertSame(['status' => 'ok', 'autostart' => false, 'root' => $this->project], $this->autostart($this->project));
        assertSame(['status' => 'ok', 'autostart' => true, 'root' => $this->project], $this->autostart($this->project, 'on'));
        assertSame(true, $this->autostart($this->project)['autostart']);
        assertSame(['roots' => [$this->project]], json_decode((string) file_get_contents($this->tempDir . '/pane-autostart.json'), true));
        assertSame(false, $this->autostart($this->project, 'off')['autostart']);
    }

    #[Group('cli')]
    public function testACorruptFileIsAnErrorAnswerNotAFailure(): void
    {
        $bytes = '{"roots": [';
        file_put_contents($this->tempDir . '/pane-autostart.json', $bytes);

        $report = $this->autostart($this->project, 'on');

        assertSame('error', $report['status']);
        assertSame($this->project, $report['root']);
        assertSame(true, str_contains((string) $report['reason'], 'is not valid JSON'));
        assertSame($bytes, (string) file_get_contents($this->tempDir . '/pane-autostart.json'));
        // Reading never fails: a corrupt file reads as off.
        assertSame(['status' => 'ok', 'autostart' => false, 'root' => $this->project], $this->autostart($this->project));
    }

    #[Group('cli')]
    public function testAnUnknownValueIsRefused(): void
    {
        $error = captureThrows(fn() => $this->autostart($this->project, 'maybe'), InvalidArgumentException::class);

        assertSame(true, str_contains($error->getMessage(), '--set'));
    }

    /** @return array<string, mixed> */
    private function autostart(string $path, ?string $set = null): array
    {
        $context = new CliCommandContext(new CliOptionParser(), new CliInputLoader(), new RuntimeFactory(self::repositoryRoot()), $this->tempDir . '/knossos.sqlite');
        $options = ['json' => ['true']] + ($set === null ? [] : ['set' => [$set]]);
        ob_start();
        try {
            (new AutostartCommand())->run('autostart', [$path], $options, $context);
        } finally {
            $output = (string) ob_get_clean();
        }

        return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    }
}
