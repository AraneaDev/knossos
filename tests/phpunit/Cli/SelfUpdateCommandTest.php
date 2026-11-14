<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Cli;

use InvalidArgumentException;
use Knossos\Cli\CliCommand;
use Knossos\Cli\CliCommandContext;
use Knossos\Cli\CliInputLoader;
use Knossos\Cli\CliOptionParser;
use Knossos\Cli\Command\SelfUpdateCommand;
use Knossos\Install\GitHubReleaseSource;
use Knossos\Install\Installation;
use Knossos\Install\InstallException;
use Knossos\Install\ReleaseProbe;
use Knossos\Install\ReleaseSource;
use Knossos\Install\UpdateCheck;
use Knossos\Runtime\RuntimeFactory;
use Knossos\Runtime\Version;
use Knossos\Tests\Phpunit\Install\FakeProbe;
use Knossos\Tests\Phpunit\Install\ForbiddenSource;
use Knossos\Tests\Phpunit\Install\InstallFixtures;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/** What `self-update` says and returns, with a local release directory and a fake probe. */
final class SelfUpdateCommandTest extends KnossosTestCase
{
    use InstallFixtures;

    #[Group('cli')]
    public function testItIsRoutedAndKnowsItsOptions(): void
    {
        $command = new SelfUpdateCommand();

        assertSame(true, $command->supports('self-update'));
        assertSame(false, $command->supports('update'));
        assertSame(['check', 'rollback', 'version', 'json', 'quiet'], $command->allowedOptions('self-update'));
    }

    #[Group('cli')]
    public function testASourceCheckoutIsRefusedWithTheGitMessage(): void
    {
        mkdir($this->work . '/checkout');
        $installation = new Installation($this->work . '/data', $this->work . '/checkout');

        $error = self::captureThrown(fn() => $this->invoke([], $installation, new ForbiddenSource()), InstallException::class);

        assertSame('This is a source checkout: update it with git pull.', $error->getMessage());
        // The hook's background call stays silent and makes no lookup in a checkout.
        [$status, $output] = $this->invoke(['check' => ['true'], 'quiet' => ['true']], $installation, new ForbiddenSource());
        assertSame([CliCommand::EXIT_OK, ''], [$status, $output]);
    }

    #[Group('cli')]
    public function testAContainerInstallIsNotUpdatedHere(): void
    {
        $data = $this->installed('d', '1.0.0');
        file_put_contents($data . '/install.json', '{"mode": "container", "version": "1.0.0"}');

        $error = self::captureThrown(fn() => $this->invoke([], $this->native($data), new ForbiddenSource()), InstallException::class);

        assertSame(true, str_contains($error->getMessage(), 'updated by the launcher'));
    }

    #[Group('cli')]
    public function testAMissingRecordSendsYouBackToTheInstaller(): void
    {
        $data = $this->installed('d', '1.0.0');
        unlink($data . '/install.json');

        $error = self::captureThrown(fn() => $this->invoke([], $this->native($data), new ForbiddenSource()), InstallException::class);

        assertSame(true, str_contains($error->getMessage(), 'run the installer again'));
    }

    #[Group('cli')]
    public function testOptionsThatContradictEachOtherAreRefused(): void
    {
        $data = $this->installed('d', '1.0.0');
        foreach ([['quiet' => ['true']], ['check' => ['true'], 'rollback' => ['true']], ['check' => ['true'], 'version' => ['1.0.0']], ['rollback' => ['true'], 'version' => ['1.0.0']]] as $options) {
            self::assertThrowsWith(fn() => $this->invoke($options, $this->native($data), new ForbiddenSource()), InvalidArgumentException::class);
        }
    }

    #[Group('cli')]
    public function testCheckExitsTenWhenANewerReleaseExists(): void
    {
        $data = $this->installed('d', Version::CURRENT);
        $this->publish($this->work . '/r', '999.0.0');

        [$status, $output] = $this->invoke(['check' => ['true']], $this->native($data));
        assertSame(10, $status);
        assertSame('Knossos 999.0.0 is available (you have ' . Version::CURRENT . '): run knossos self-update.', $output);

        [$status, $output] = $this->invoke(['check' => ['true'], 'json' => ['true']], $this->native($data));
        assertSame(10, $status);
        assertSame(['current' => Version::CURRENT, 'latest' => '999.0.0', 'update' => true], json_decode($output, true));
        assertSame('999.0.0', (new UpdateCheck($data))->read()['latest']);
    }

    #[Group('cli')]
    public function testCheckExitsZeroWhenCurrent(): void
    {
        $data = $this->installed('d', Version::CURRENT);
        $this->publish($this->work . '/r', Version::CURRENT);

        assertSame([0, 'Knossos ' . Version::CURRENT . ' is the latest version.'], $this->invoke(['check' => ['true']], $this->native($data)));
    }

    #[Group('cli')]
    public function testQuietCheckRefreshesTheCacheOnlyWhenDueAndSaysNothing(): void
    {
        $data = $this->installed('d', Version::CURRENT);
        $this->publish($this->work . '/r', '999.0.0');

        assertSame([0, ''], $this->invoke(['check' => ['true'], 'quiet' => ['true']], $this->native($data)));
        assertSame('999.0.0', (new UpdateCheck($data))->read()['latest']);

        // Fresh now: a source that fails the test if asked proves there is no second lookup.
        assertSame([0, ''], $this->invoke(['check' => ['true'], 'quiet' => ['true']], $this->native($data), new ForbiddenSource()));
    }

    #[Group('cli')]
    public function testQuietCheckSwallowsAFailedLookup(): void
    {
        $data = $this->installed('d', Version::CURRENT);

        assertSame([0, ''], $this->invoke(['check' => ['true'], 'quiet' => ['true']], $this->native($data), new ForbiddenSource()));
        // The failed look is recorded as an attempt, so the next session start does not try again.
        self::assertSame(null, (new UpdateCheck($data))->read()['latest']);
        self::assertSame(false, (new UpdateCheck($data))->due());
        // Without --quiet the same failure is an error.
        self::captureThrown(fn() => $this->invoke(['check' => ['true']], $this->native($data), new ForbiddenSource()), InstallException::class);
    }

    #[Group('cli')]
    public function testItUpdatesAndRollsBack(): void
    {
        $data = $this->installed('d', '1.0.0');
        $this->publish($this->work . '/r', '2.0.0');

        assertSame([0, 'Updated Knossos from 1.0.0 to 2.0.0.'], $this->invoke([], $this->native($data)));
        assertSame([0, 'Knossos 2.0.0 is already the latest version.'], $this->invoke([], $this->native($data)));
        [$status, $output] = $this->invoke(['rollback' => ['true'], 'json' => ['true']], $this->native($data));
        assertSame(0, $status);
        assertSame(['status' => 'rolled back', 'from' => '2.0.0', 'to' => '1.0.0'], json_decode($output, true));
        assertSame([0, 'Updated Knossos from 1.0.0 to 2.0.0.'], $this->invoke(['version' => ['2.0.0']], $this->native($data)));
        assertSame([0, 'Rolled back from 2.0.0 to 1.0.0.'], $this->invoke(['rollback' => ['true']], $this->native($data)));
    }

    /** An installation whose code lives in the data dir's versions, like a real one. */
    private function native(string $data): Installation
    {
        return new Installation($data, $data . '/versions/' . (new \Knossos\Install\VersionSwitch($data))->current());
    }

    /**
     * Run the command; return exit status and what it printed.
     *
     * @param array<string, list<string>> $options
     * @return array{int, string}
     */
    private function invoke(array $options, Installation $installation, ?ReleaseSource $source = null, ?ReleaseProbe $probe = null): array
    {
        $context = new CliCommandContext(new CliOptionParser(), new CliInputLoader(), new RuntimeFactory(self::repositoryRoot()), $this->work . '/knossos.sqlite');
        $source ??= new GitHubReleaseSource($this->work . '/r', null, \Knossos\Install\ReleaseAsset::platform());
        ob_start();
        try {
            $status = (new SelfUpdateCommand($installation, $source, $probe ?? new FakeProbe()))->run('self-update', [], $options, $context);
        } finally {
            $output = (string) ob_get_clean();
        }

        return [$status, trim($output)];
    }
}
