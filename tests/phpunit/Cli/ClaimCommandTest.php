<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Cli;

use InvalidArgumentException;
use Knossos\Cli\CliCommand;
use Knossos\Cli\CliCommandContext;
use Knossos\Cli\CliInputLoader;
use Knossos\Cli\CliOptionParser;
use Knossos\Cli\Command\ClaimCommand;
use Knossos\Runtime\RuntimeFactory;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\Support\PinsContainer;
use PHPUnit\Framework\Attributes\Group;

/**
 * What `claim --json` reports and writes.
 *
 * The caller is a shell wrapper that turns any nonzero exit into silence, so a
 * roots file it cannot use is an answer on stdout, not a failure to exit on.
 */
final class ClaimCommandTest extends KnossosTestCase
{
    use PinsContainer;

    private string $tempDir;
    private string $home;
    private string $repo;
    private string|false $savedHome;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = (string) realpath(sys_get_temp_dir()) . '/knossos-claim-cmd-' . bin2hex(random_bytes(4));
        $this->home = $this->tempDir . '/home';
        $this->repo = $this->home . '/work/repo';
        mkdir($this->repo . '/.git', 0o755, true);
        $this->savedHome = getenv('HOME');
        putenv('HOME=' . $this->home);
        $this->pinContainer(false);
    }

    protected function tearDown(): void
    {
        putenv(is_string($this->savedHome) ? 'HOME=' . $this->savedHome : 'HOME');
        $this->restoreContainer();
        exec('rm -rf ' . escapeshellarg($this->tempDir));
        parent::tearDown();
    }

    #[Group('cli')]
    public function testAddedThenPresent(): void
    {
        assertSame(['status' => 'added', 'root' => $this->repo, 'reason' => null], $this->claim($this->repo, execute: true));
        assertSame(['roots' => [$this->repo]], json_decode((string) file_get_contents($this->rootsFile()), true));
        assertSame(['status' => 'present', 'root' => $this->repo, 'reason' => null], $this->claim($this->repo . '/', execute: true));
    }

    #[Group('cli')]
    public function testPreviewWritesNothing(): void
    {
        assertSame(['status' => 'would-add', 'root' => $this->repo, 'reason' => null], $this->claim($this->repo));
        assertSame(false, is_file($this->rootsFile()));
    }

    #[Group('cli')]
    public function testARepositoryInsideABroadRootStillGetsItsOwnEntry(): void
    {
        mkdir($this->repo . '/src', 0o755, true);
        file_put_contents($this->rootsFile(), json_encode(['roots' => [$this->home]]));

        $report = $this->claim($this->repo . '/src', execute: true);

        assertSame('added', $report['status']);
        assertSame(['roots' => [$this->home, $this->repo]], json_decode((string) file_get_contents($this->rootsFile()), true));
    }

    #[Group('cli')]
    public function testAskWritesNothing(): void
    {
        $report = $this->claim($this->home, execute: true, exit: $exit);

        assertSame(CliCommand::EXIT_OK, $exit);
        assertSame('ask', $report['status']);
        assertSame($this->home, $report['root']);
        assertSame(true, is_string($report['reason']) && $report['reason'] !== '');
        assertSame(false, is_file($this->rootsFile()));
    }

    #[Group('cli')]
    public function testAContainerIsFixed(): void
    {
        $this->pinContainer(true);

        $report = $this->claim($this->repo, execute: true, exit: $exit);

        assertSame(CliCommand::EXIT_OK, $exit);
        assertSame('fixed', $report['status']);
        assertSame(true, str_contains((string) $report['reason'], 'host path'));
        assertSame(false, is_file($this->rootsFile()));
    }

    #[Group('cli')]
    public function testACorruptRootsFileIsLeftUntouched(): void
    {
        $bytes = '{"roots": [';
        file_put_contents($this->rootsFile(), $bytes);

        $report = $this->claim($this->repo, execute: true, exit: $exit);

        assertSame(CliCommand::EXIT_OK, $exit);
        assertSame('error', $report['status']);
        assertSame($this->repo, $report['root']);
        assertSame(true, str_contains((string) $report['reason'], 'is not valid JSON'));
        assertSame($bytes, (string) file_get_contents($this->rootsFile()));
    }

    #[Group('cli')]
    public function testAnUnreadableRootsFileIsAnErrorAndExitsZero(): void
    {
        // A directory where the file belongs fails for any user, which chmod does not under root.
        mkdir($this->rootsFile(), 0o755, true);

        $report = $this->claim($this->repo, execute: true, exit: $exit);

        assertSame(CliCommand::EXIT_OK, $exit);
        assertSame('error', $report['status']);
        assertSame($this->repo, $report['root']);
        assertSame(true, is_string($report['reason']) && $report['reason'] !== '');
        assertSame(true, is_dir($this->rootsFile()));
    }

    #[Group('cli')]
    public function testAFolderThatIsAlreadyARootIsNotWalkedUpPast(): void
    {
        mkdir($this->repo . '/packages/api', 0o755, true);
        $package = (string) realpath($this->repo . '/packages/api');
        file_put_contents($this->rootsFile(), json_encode(['roots' => [$package]]));
        $bytes = (string) file_get_contents($this->rootsFile());

        $report = $this->claim($package, execute: true);

        assertSame(['status' => 'present', 'root' => $package, 'reason' => null], $report);
        assertSame($bytes, (string) file_get_contents($this->rootsFile()));
    }

    #[Group('cli')]
    public function testAnAllowedHomeIsPresentRatherThanAQuestion(): void
    {
        file_put_contents($this->rootsFile(), json_encode(['roots' => [$this->home]]));

        assertSame(['status' => 'present', 'root' => $this->home, 'reason' => null], $this->claim($this->home, execute: true));
    }

    #[Group('cli')]
    public function testAMissingDirectoryIsAnError(): void
    {
        $error = captureThrows(fn() => $this->claim($this->tempDir . '/nowhere'), InvalidArgumentException::class);

        assertSame(true, str_contains($error->getMessage(), 'is not an existing directory'));
    }

    private function rootsFile(): string
    {
        return $this->tempDir . '/roots.json';
    }

    /**
     * @param-out int $exit
     * @return array<string, mixed>
     */
    private function claim(string $path, bool $execute = false, ?int &$exit = null): array
    {
        $context = new CliCommandContext(new CliOptionParser(), new CliInputLoader(), new RuntimeFactory(self::repositoryRoot()), $this->tempDir . '/knossos.sqlite');
        $options = ['json' => ['true']] + ($execute ? ['execute' => ['true']] : []);
        ob_start();
        try {
            $exit = (new ClaimCommand())->run('claim', [$path], $options, $context);
        } finally {
            $output = (string) ob_get_clean();
        }

        return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    }
}
