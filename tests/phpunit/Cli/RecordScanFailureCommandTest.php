<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Cli;

use InvalidArgumentException;
use Knossos\Cli\CliCommand;
use Knossos\Cli\CliCommandContext;
use Knossos\Cli\CliInputLoader;
use Knossos\Cli\CliOptionParser;
use Knossos\Cli\Command\RecordScanFailureCommand;
use Knossos\Runtime\RuntimeFactory;
use Knossos\Scan\ScanFailureLog;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/** What `record-scan-failure` keeps for the wrappers that saw a scan killed or out of time. */
final class RecordScanFailureCommandTest extends KnossosTestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = (string) realpath(sys_get_temp_dir()) . '/knossos-record-failure-' . bin2hex(random_bytes(4));
        mkdir($this->directory . '/project', 0o700, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->directory));
        parent::tearDown();
    }

    /** @param array<string, list<string>> $options */
    private function record(array $options): int
    {
        $context = new CliCommandContext(new CliOptionParser(), new CliInputLoader(), new RuntimeFactory(self::repositoryRoot()), $this->directory . '/knossos.sqlite');

        return (new RecordScanFailureCommand())->run('record-scan-failure', [$this->directory . '/project'], $options, $context);
    }

    #[Group('cli')]
    public function testAKilledScanIsKeptWithItsSignalAndWho(): void
    {
        assertSame(CliCommand::EXIT_OK, $this->record(['class' => ['killed'], 'signal' => ['15'], 'started-by' => ['pane']]));

        $entry = (new ScanFailureLog($this->directory . '/scan-failures.jsonl'))->recent(5)[0];
        assertSame('killed', $entry['class']);
        assertSame(15, $entry['signal']);
        assertSame('pane', $entry['started_by']);
        assertSame($this->directory . '/project', $entry['root']);
    }

    #[Group('cli')]
    public function testAnUnknownClassOrSourceIsRefused(): void
    {
        assertThrows(fn() => $this->record(['class' => ['exploded']]), InvalidArgumentException::class);
        assertThrows(fn() => $this->record(['class' => ['killed'], 'started-by' => ['nobody']]), InvalidArgumentException::class);
        assertSame(false, is_file($this->directory . '/scan-failures.jsonl'));
    }

    #[Group('cli')]
    public function testASessionBriefTimeoutIsKeptWithoutADatabaseToAsk(): void
    {
        $this->record(['class' => ['brief-timeout']]);

        $entry = (new ScanFailureLog($this->directory . '/scan-failures.jsonl'))->recent(5)[0];
        assertSame('brief-timeout', $entry['class']);
        assertSame('cli', $entry['started_by']);
        assertSame('The session brief did not finish in time.', $entry['message']);
    }
}
